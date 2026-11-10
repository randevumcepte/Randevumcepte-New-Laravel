<?php

namespace App\Services\Satis;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Exceptions\BulunamadiException;
use App\Models\Salonlar;
use App\Services\Tahsilat\TahsilatSilServisi;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Support\Sema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Satış Takibi satır işlemleri — eski StoreAdminController metotlarının aynası
 * (web + Flutter AYNI uç; kod tekrarı yok):
 *   - faturaIsaretle  ← adisyonFaturaIsaretle  (toggle fatura_kesildi)
 *   - studyoOdeme     ← adisyonOdemeIsaretle   (tamOde / odemeGeriAl)
 *   - faturasizGizle  ← faturasizGizleToggle   (salon geneli ayar)
 * Tahsilat yazma/iade KENDİSİ YAPMAZ: v2'nin tek tahsilat yazıcısı TahsilatYazServisi
 * ve iade servisi TahsilatSilServisi kullanılır. Yetki kararları controller'da.
 */
class AdisyonIslemServisi
{
    public function __construct(
        private TahsilatYazServisi $tahsilatYaz,
        private TahsilatSilServisi $tahsilatSil,
    ) {}

    public function adisyonSalonId(int $adisyonId): ?int
    {
        $s = DB::table('adisyonlar')->where('id', $adisyonId)->value('salon_id');

        return $s ? (int) $s : null;
    }

    public function studyoModu(int $salonId): bool
    {
        return Salonlar::studyoModu($salonId);
    }

    public function faturasizGizleAktif(int $salonId): bool
    {
        return (bool) DB::table('salonlar')->where('id', $salonId)->value('faturasiz_gizle');
    }

    /** fatura_kesildi'yi tersine çevirir; kesildiyse tarih + kesen personel yazılır. */
    public function faturaIsaretle(int $adisyonId, int $salonId, ?int $personelId): array
    {
        $a = DB::table('adisyonlar')->where('id', $adisyonId)->where('salon_id', $salonId)->first(['id', 'fatura_kesildi']);
        if (! $a) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }
        $yeni = ! ((bool) $a->fatura_kesildi);
        DB::table('adisyonlar')->where('id', $adisyonId)->update([
            'fatura_kesildi'           => $yeni ? 1 : 0,
            'fatura_kesildi_tarihi'    => $yeni ? now() : null,
            'fatura_kesen_personel_id' => $yeni ? $personelId : null,
        ]);

        return ['adisyon_id' => $adisyonId, 'fatura_kesildi' => $yeni ? 1 : 0];
    }

    /** Salon geneli faturasız gizle ayarını tersine çevirir. */
    public function faturasizGizle(int $salonId): array
    {
        $mevcut = $this->faturasizGizleAktif($salonId);
        DB::table('salonlar')->where('id', $salonId)->update(['faturasiz_gizle' => $mevcut ? 0 : 1]);

        return ['faturasiz_gizle' => $mevcut ? 0 : 1];
    }

    /**
     * Stüdyo "Ödeme Alındı" (alindi=true): adisyonun kalan borcu TEK tahsilatla
     * kapatılır — TahsilatYazServisi (kalem-bazlı havuz dağıtımı, idempotent).
     * "Geri Al" (false): adisyonun TÜM tahsilatları TahsilatSilServisi ile iade edilir.
     * Ardından adisyonlar.odendi / odendi_tarihi güncellenir.
     *
     * @param array{yetkili_id:int,olusturan_id:?int} $kim
     */
    public function studyoOdeme(int $adisyonId, int $salonId, bool $alindi, array $kim): array
    {
        if (! DB::table('adisyonlar')->where('id', $adisyonId)->where('salon_id', $salonId)->exists()) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }

        DB::transaction(function () use ($adisyonId, $salonId, $alindi, $kim) {
            if ($alindi) {
                $plan = $this->tahsilatYaz->planla(['adisyon_id' => $adisyonId, 'salon_id' => $salonId, 'tutar' => 0]);
                $kalanKurus = (int) ($plan['ozet']['toplam_kalan_kurus'] ?? 0);
                if ($kalanKurus > 0) {
                    $this->tahsilatYaz->yaz([
                        'adisyon_id'       => $adisyonId,
                        'salon_id'         => $salonId,
                        'tutar'            => AdisyonHesap::kurusToTl($kalanKurus),
                        'odeme_yontemi_id' => DB::table('odeme_yontemleri')->orderBy('id')->value('id') ?: 1,
                        'odeme_tarihi'     => now()->toDateString(),
                        'idempotency_key'  => 'studyo-odeme-'.$adisyonId.'-'.Str::uuid(),
                    ], ['yetkili_id' => $kim['yetkili_id'], 'salon_id' => $salonId, 'olusturan_id' => $kim['olusturan_id'] ?? null]);
                }
            } else {
                foreach (DB::table('tahsilatlar')->where('adisyon_id', $adisyonId)->pluck('id') as $tahsilatId) {
                    $this->tahsilatSil->sil((int) $tahsilatId, ['yetkili_id' => $kim['yetkili_id'], 'salon_id' => $salonId]);
                }
            }

            $guncelle = [];
            if (Sema::kolonVar('adisyonlar', 'odendi')) {
                $guncelle['odendi'] = $alindi ? 1 : 0;
            }
            if (Sema::kolonVar('adisyonlar', 'odendi_tarihi')) {
                $guncelle['odendi_tarihi'] = $alindi ? now() : null;
            }
            if ($guncelle) {
                DB::table('adisyonlar')->where('id', $adisyonId)->update($guncelle);
            }
        });

        return ['adisyon_id' => $adisyonId, 'odendi' => $alindi ? 1 : 0];
    }

    /**
     * Stüdyo: "Ödeme Alınma Tarihi"ni elle güncelle. Ödenmemişse önce tam tahsilat (odendi=1).
     *
     * @param array{yetkili_id:int,olusturan_id:?int} $kim
     */
    /**
     * Satış tarihi (eski adisyonTarihGuncelle): adisyonlar.tarih + bu satışın tahsilatlarının
     * odeme_tarihi aynı güne (kasa raporu ödeme tarihinden okur; ikisi hizalı kalsın).
     */
    public function satisTarihiGuncelle(int $adisyonId, int $salonId, string $tarih): array
    {
        $tarih = date('Y-m-d', strtotime($tarih));
        DB::transaction(function () use ($adisyonId, $salonId, $tarih) {
            DB::table('adisyonlar')->where('id', $adisyonId)->where('salon_id', $salonId)->update(['tarih' => $tarih]);
            DB::table('tahsilatlar')->where('adisyon_id', $adisyonId)->where('salon_id', $salonId)->update(['odeme_tarihi' => $tarih]);
        });

        return ['adisyon_id' => $adisyonId, 'tarih' => $tarih];
    }

    public function studyoOdemeTarihi(int $adisyonId, int $salonId, ?string $tarih, array $kim): array
    {
        $tarih = date('Y-m-d H:i:s', $tarih ? strtotime($tarih) : time());

        return DB::transaction(function () use ($adisyonId, $salonId, $tarih, $kim) {
            if ((int) DB::table('adisyonlar')->where('id', $adisyonId)->where('salon_id', $salonId)->value('odendi') !== 1) {
                $this->studyoOdeme($adisyonId, $salonId, true, $kim);
            }
            DB::table('adisyonlar')->where('id', $adisyonId)->update(['odendi_tarihi' => $tarih]);

            return ['adisyon_id' => $adisyonId, 'odendi' => 1, 'odendi_tarihi' => $tarih];
        });
    }
}
