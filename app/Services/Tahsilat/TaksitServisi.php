<?php

namespace App\Services\Tahsilat;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Domain\Tahsilat\TaksitPlani;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Adisyonlar;
use App\Models\Alacaklar;
use App\Models\TaksitVadeleri;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Taksitli tahsilat (eski taksitekleguncelle, "Taksit Yap").
 *
 * planYaz() plan, vade ve vade başına alacak satırlarını yazar; web ve mobil aynı yazıcıyı kullanır.
 * adisyonTaksiti() web akışıdır: ön ödeme normal tahsilat olarak yazılır (TahsilatYazServisi, aynı dağıtım), kalan
 * borç adisyonun açık kalemlerinden sunucuda hesaplanır ve taksitlere bölünür. Eski web tutarı ekrandan alıyordu.
 */
class TaksitServisi
{
    public function __construct(private TahsilatYazServisi $tahsilat) {}

    /** Plan + vadeler + her vadeye bir alacak. Çağıranın transaction'ı içinde çalışır. */
    public function planYaz(int $salonId, ?int $musteriId, ?int $adisyonId, string $baslangic, int $vadeSayisi, int $toplamKurus, ?int $olusturanId): int
    {
        $vadeler = TaksitPlani::vadeler($baslangic, $vadeSayisi, $toplamKurus);

        $planId = (int) DB::table('taksitli_tahsilatlar')->insertGetId(array_filter([
            'user_id' => $musteriId, 'adisyon_id' => $adisyonId, 'vade_sayisi' => $vadeSayisi, 'salon_id' => $salonId,
            'olusturan_id' => $olusturanId, 'created_at' => now(), 'updated_at' => now(),
        ], fn ($v) => $v !== null));

        foreach ($vadeler as $i => $v) {
            $vade = new TaksitVadeleri();
            $vade->taksitli_tahsilat_id = $planId;
            $vade->vade_tarih = $v['tarih'];
            $vade->odendi = false;
            $vade->tutar = AdisyonHesap::kurusToTl($v['tutar_kurus']);
            $vade->save();

            $a = new Alacaklar();
            $a->adisyon_id = $adisyonId;
            $a->salon_id = $salonId;
            $a->user_id = $musteriId;
            $a->tutar = $vade->tutar;
            $a->taksitli_tahsilat_id = $planId;
            $a->taksit_vade_id = $vade->id;
            $a->planlanan_odeme_tarihi = $vade->vade_tarih;
            $a->aciklama = 'Taksit '.($i + 1).'/'.$vadeSayisi;
            $a->olusturan_id = $olusturanId;
            $a->save();
        }

        return $planId;
    }

    /**
     * Web "Taksit Yap": $g = on_odeme, on_odeme_yontemi_id, odeme_tarihi, vade, vade_baslangic_tarihi, notlar,
     * idempotency_key. $meta = yetkili_id, salon_id, olusturan_id (personel).
     */
    public function adisyonTaksiti(int $adisyonId, array $g, array $meta): array
    {
        $onOdemeKurus = AdisyonHesap::tlToKurus($g['on_odeme'] ?? 0);
        if ($onOdemeKurus < 0) {
            throw new GecersizIslemException('Ön ödeme tutarı negatif olamaz.');
        }
        $vadeSayisi = (int) ($g['vade'] ?? 0);
        $baslangic = (string) ($g['vade_baslangic_tarihi'] ?? '');
        TaksitPlani::tarihler($baslangic, 1); // tarih hatası yazmadan önce
        $anahtar = (string) ($g['idempotency_key'] ?? '');
        $yetkiliId = isset($meta['yetkili_id']) ? (int) $meta['yetkili_id'] : null;
        $salonId = (int) $meta['salon_id'];

        $sonuc = Idempotency::calistir('taksit', $anahtar, $yetkiliId, $salonId,
            function () use ($adisyonId, $g, $meta, $onOdemeKurus, $vadeSayisi, $baslangic, $anahtar, $yetkiliId, $salonId) {
                $adisyon = Adisyonlar::where('id', $adisyonId)->lockForUpdate()->first();
                if (! $adisyon) {
                    throw new BulunamadiException('Adisyon bulunamadı');
                }

                $kalanKurus = array_sum(array_column($this->tahsilat->acikKalanlar($adisyonId), 'kalan_kurus'));
                if ($kalanKurus <= 0) {
                    throw new GecersizIslemException('Taksitlendirilecek kalan borç yok.');
                }
                if ($onOdemeKurus >= $kalanKurus) {
                    throw new GecersizIslemException('Ön ödeme kalan borcun tamamını karşılıyor; taksit yerine tahsilat alın.');
                }

                $tahsilatId = null;
                if ($onOdemeKurus > 0) {
                    $tahsilatId = $this->tahsilat->yaz([
                        'adisyon_id' => $adisyonId, 'salon_id' => $salonId,
                        'tutar' => AdisyonHesap::kurusToTl($onOdemeKurus),
                        'odeme_yontemi_id' => $g['on_odeme_yontemi_id'] ?? null,
                        'odeme_tarihi' => $g['odeme_tarihi'] ?? date('Y-m-d'),
                        'notlar' => $g['notlar'] ?? null,
                        'idempotency_key' => $anahtar.':on-odeme',
                    ], $meta)['tahsilat_id'];
                }

                // Ön ödeme dağıtıldıktan sonra kalanı olan kalemler plana bağlanır; plan tutarı onların kalanıdır.
                $kalemler = $this->tahsilat->acikKalanlar($adisyonId);
                $taksitKurus = array_sum(array_column($kalemler, 'kalan_kurus'));
                $planId = $this->planYaz($salonId, $adisyon->user_id !== null ? (int) $adisyon->user_id : null, $adisyonId,
                    $baslangic, $vadeSayisi, $taksitKurus, $yetkiliId);
                foreach ($kalemler as $k) {
                    $k['model']::where('id', $k['id'])->update(['taksitli_tahsilat_id' => $planId]);
                }

                return [
                    'taksitli_tahsilat_id' => $planId,
                    'tahsilat_id' => $tahsilatId,
                    'adisyon_id' => $adisyonId,
                    'vade_sayisi' => $vadeSayisi,
                    'taksit_tutari' => AdisyonHesap::kurusToTl($taksitKurus),
                    'on_odeme' => AdisyonHesap::kurusToTl($onOdemeKurus),
                ];
            });

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'taksit_ekle', 'taksitli_tahsilat', $sonuc['taksitli_tahsilat_id'],
                'Vade: '.$vadeSayisi.' - Tutar: '.$sonuc['taksit_tutari'].' - Ön ödeme: '.$sonuc['on_odeme'], 'Taksitli tahsilat oluşturuldu');
        }

        return $sonuc;
    }
}
