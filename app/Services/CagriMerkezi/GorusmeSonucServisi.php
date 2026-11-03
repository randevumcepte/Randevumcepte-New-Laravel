<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\SonucKaydiPlani;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Görüşme sonucu YAZIMI — eski santral_not_ekle, cagri_randevu_verildi_isaretle,
 * cagri_arama_randevu_iptal, arama_randevu_ertele, cagri_arama_randevu_ekle karşılığı.
 *
 * Karar mantığı saf Domain\CagriMerkezi\SonucKaydiPlani'da; burada yalnız kilit + erişim +
 * yazım + görüşme geçmişi satırı + log. Her yazım tek transaction (eski kodda yoktu:
 * aranacak kaydı yazılıp gorusme_notlari düşerse iz kaybolabiliyordu).
 */
class GorusmeSonucServisi
{
    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private AramaListesiServisi $listeler,
    ) {}

    /**
     * "Sonucu Kaydet".
     *
     * @param array{sonuc:?int, not:?string, tarih:?string, saat:?string, satis_tutari:?float,
     *              kategori_id:?int, alt_kategori_id:?int, arama_personel_id:?int} $v
     */
    public function kaydet(CagriMerkeziErisim $e, int $aranacakId, array $v): array
    {
        if (SonucKaydiPlani::bosMu($v)) {
            throw new GecersizIslemException('Bir görüşme sonucu seçin, tekrar arama oluşturun ya da not yazın.');
        }
        $simdi = now()->format('Y-m-d H:i:s');

        $sonuc = DB::transaction(function () use ($e, $aranacakId, $v, $simdi) {
            $k = $this->erisim->kayit($aranacakId, $e, true);
            $plan = SonucKaydiPlani::olustur((array) $k, $v, $simdi);
            $kolonlar = $plan->kolonlar + ['updated_at' => $simdi];

            // Arama randevusu verilirken ARAYACAK PERSONEL listenin personelinden farklıysa kayıt o
            // personelin "Arama Randevuları (Manuel)" listesine taşınır (onun kuyruğunda/takviminde çıkar).
            if ($plan->yeniAramaRandevusu && ! empty($v['arama_personel_id'])) {
                $hedef = $this->erisim->personel((int) $v['arama_personel_id'], $e);
                if (! $e->personeleAtayabilir((int) $hedef->id)) {
                    throw new YetkisizErisimException('Arama randevusunu yalnız kendinize verebilirsiniz.');
                }
                if ((int) $hedef->id !== (int) $k->liste_personel_id) {
                    $kolonlar['arama_id'] = $this->listeler->manuelListeId($e, (int) $hedef->id);
                }
            }
            DB::table('aranacak_musteriler')->where('id', $aranacakId)->update($kolonlar);

            [$katId, $altId] = $this->kategoriler($e, $v['kategori_id'] ?? null, $v['alt_kategori_id'] ?? null);
            $this->gecmiseYaz($e, $k, $kolonlar['arama_id'] ?? (int) $k->arama_id, $plan->durum, (string) ($v['not'] ?? ''), [
                'kategori_id' => $katId, 'alt_kategori_id' => $altId, 'satis_tutari' => $plan->satisTutari,
            ]);

            return ['durum' => $plan->durum, 'arama_id' => (int) ($kolonlar['arama_id'] ?? $k->arama_id), 'user_id' => (int) $k->user_id];
        });

        $this->log($e, 'cagri_gorusme_sonucu', $aranacakId, $sonuc['user_id'], 'Görüşme sonucu: '.AramaSonucu::metin($sonuc['durum']),
            array_filter(['sonuc' => $sonuc['durum'], 'tarih' => $v['tarih'] ?? null, 'saat' => $v['saat'] ?? null], fn ($x) => $x !== null));

        return $sonuc + ['durum_metin' => AramaSonucu::metin($sonuc['durum'])];
    }

    /**
     * Normal salon randevusu verildi → "Randevu Verildi" rozeti + sonuç OTOMATİK 8 (Randevu
     * Oluşturuldu). Kayıt LİSTEDE KALIR; bekleyen arama randevusuysa hatırlatma susar.
     */
    public function randevuVerildi(CagriMerkeziErisim $e, int $aranacakId): array
    {
        return $this->tamamla($e, $aranacakId, AramaSonucu::RANDEVU_OLUSTURULDU, ['randevu_olusturuldu' => 1], true, 'cagri_randevu_verildi');
    }

    /** Arama randevusunu iptal et (9): bekleyen kuyruktan çıkar, takvimde "İptal" görünür. */
    public function iptal(CagriMerkeziErisim $e, int $aranacakId): array
    {
        return $this->tamamla($e, $aranacakId, AramaSonucu::IPTAL, [], false, 'cagri_arama_randevu_iptal');
    }

    /** Arama randevusunu ertele: yeni zaman + hatırlatma döngüsü sıfır (durum 3'te kalır). */
    public function ertele(CagriMerkeziErisim $e, int $aranacakId, string $tarih, string $saat): array
    {
        $uid = DB::transaction(function () use ($e, $aranacakId, $tarih, $saat) {
            $k = $this->erisim->kayit($aranacakId, $e, true);
            DB::table('aranacak_musteriler')->where('id', $aranacakId)->update(SonucKaydiPlani::aramaRandevusu($tarih, $saat) + ['updated_at' => now()]);

            return (int) $k->user_id;
        });
        $this->log($e, 'cagri_arama_randevu_ertele', $aranacakId, $uid, 'Arama randevusu ertelendi: '.date('d.m.Y', strtotime($tarih)).' '.$saat);

        return ['tarih' => $tarih, 'saat' => $saat];
    }

    /** Takvimden MANUEL arama randevusu (durum 3) — personelin manuel listesine yazar. */
    public function manuelEkle(CagriMerkeziErisim $e, int $musteriId, int $personelId, string $tarih, string $saat, ?string $not): array
    {
        $per = $this->erisim->personel($personelId, $e);
        if (! $e->personeleAtayabilir((int) $per->id)) {
            throw new YetkisizErisimException('Sadece kendi üzerinize ekleyebilirsiniz.');
        }
        if (! DB::table('musteri_portfoy')->where('salon_id', $e->salonId)->where('user_id', $musteriId)->exists()) {
            throw new GecersizIslemException('Bu müşteri işletmenizde bulunamadı.');
        }
        $id = DB::transaction(fn () => DB::table('aranacak_musteriler')->insertGetId(
            SonucKaydiPlani::aramaRandevusu($tarih, $saat) + [
                'user_id' => $musteriId, 'arama_id' => $this->listeler->manuelListeId($e, (int) $per->id),
                'musteri_not' => trim((string) $not) !== '' ? trim((string) $not) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        $mesaj = 'Arama randevusu eklendi: '.$per->personel_adi.' — '.date('d.m.Y', strtotime($tarih)).' '.$saat;
        $this->log($e, 'cagri_arama_randevu_ekle', (int) $id, $musteriId, $mesaj, ['personel_id' => (int) $per->id]);

        return ['id' => (int) $id, 'mesaj' => $mesaj];
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    private function tamamla(CagriMerkeziErisim $e, int $aranacakId, AramaSonucu $sonuc, array $ek, bool $gecmis, string $islem): array
    {
        $uid = DB::transaction(function () use ($e, $aranacakId, $sonuc, $ek, $gecmis) {
            $k = $this->erisim->kayit($aranacakId, $e, true);
            DB::table('aranacak_musteriler')->where('id', $aranacakId)
                ->update(SonucKaydiPlani::tamamla($sonuc, $k->ar_tamamlandi_at, now()->format('Y-m-d H:i:s')) + $ek + ['updated_at' => now()]);
            if ($gecmis) {
                $this->gecmiseYaz($e, $k, (int) $k->arama_id, $sonuc->value, '');
            }

            return (int) $k->user_id;
        });
        $this->log($e, $islem, $aranacakId, $uid, $sonuc->etiket());

        return ['durum' => $sonuc->value, 'durum_metin' => $sonuc->etiket()];
    }

    /** Çoğul görüşme geçmişi satırı (müşteriye kalıcı bağ; ses kaydı sonra eşlenir). */
    private function gecmiseYaz(CagriMerkeziErisim $e, object $k, int $listeId, int $sonuc, string $not, array $ek = []): void
    {
        DB::table('gorusme_notlari')->insert([
            'aranacak_musteri_id' => (int) $k->id,
            'arama_id'            => $listeId,
            'salon_id'            => $e->salonId,
            'user_id'             => (int) $k->user_id,
            'personel_id'         => $e->personelId,
            'not'                 => $not,
            'sonuc'               => $sonuc,
            'ses_kaydi'           => $k->ses_kaydi,
            'created_at'          => now(),
            'updated_at'          => now(),
        ] + $ek);
    }

    /** Kategori bu salonun ana kategorisi, alt kategori onun çocuğu olmalı; değilse yok sayılır. @return array{0:?int,1:?int} */
    private function kategoriler(CagriMerkeziErisim $e, ?int $katId, ?int $altId): array
    {
        if (! $katId || ! DB::table('cagri_kategorileri')->where('id', $katId)->where('salon_id', $e->salonId)->exists()) {
            return [null, null];
        }
        if ($altId && ! DB::table('cagri_kategorileri')->where('id', $altId)->where('salon_id', $e->salonId)->where('ust_id', $katId)->exists()) {
            $altId = null;
        }

        return [$katId, $altId ?: null];
    }

    private function log(CagriMerkeziErisim $e, string $islem, int $aranacakId, int $userId, string $aciklama, array $meta = []): void
    {
        $ad = (string) DB::table('users')->where('id', $userId)->value('name');
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, $islem, 'aranacak_musteri', $aranacakId, $ad ?: null, $aciklama, $meta + ['user_id' => $userId]);
    }
}
