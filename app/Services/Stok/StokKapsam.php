<?php

namespace App\Services\Stok;

use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use Illuminate\Support\Facades\DB;

/**
 * Stok kayitlarinin SALON SAHIPLIGI — tek denetim noktasi.
 *
 * Eski StokController id ile gelen kayitlari (urun-sil, kategori-sil, depo-sil…)
 * salonuna bakmadan isliyordu; baska isletmenin kaydi degistirilebiliyordu. v2'de:
 *   - Yol parametresindeki kaydin salonu cozulur (controller kapsam/yetki denetler).
 *   - Govdede gelen referanslar (kategori_id, depo_id, urun_id…) AYNI salona ait +
 *     aktif olmali; degilse 422.
 */
class StokKapsam
{
    /** Kaynak adi → [tablo, kullaniciya gosterilecek ad] (beyaz liste). */
    private const KAYNAK = [
        'urun'      => ['urunler', 'Ürün'],
        'depo'      => ['depolar', 'Depo'],
        'kategori'  => ['urun_kategoriler', 'Kategori'],
        'tedarikci' => ['tedarikciler', 'Tedarikçi'],
        'recete'    => ['hizmet_sarf_receteleri', 'Reçete'],
    ];

    /** Kaydin salon_id'si; kayit yoksa 404 (aktif/pasif fark etmez — silinmis kaydin salonu da cozulur). */
    public function salonId(string $kaynak, int $id): int
    {
        [$tablo, $ad] = self::KAYNAK[$kaynak];
        $salonId = (int) DB::table($tablo)->where('id', $id)->value('salon_id');
        if (! $salonId) {
            throw new BulunamadiException($ad.' bulunamadı');
        }

        return $salonId;
    }

    /**
     * Verilen id'lerin HEPSI bu salonun AKTIF kaydi mi? (bos/null id'ler atlanir.)
     *
     * @param  array<int|string|null>|int|string|null  $idler
     */
    public function aitOlmali(string $kaynak, array|int|string|null $idler, int $salonId): void
    {
        $idler = array_values(array_unique(array_filter(array_map('intval', (array) $idler))));
        if (! $idler) {
            return;
        }
        [$tablo, $ad] = self::KAYNAK[$kaynak];
        $bulunan = DB::table($tablo)->whereIn('id', $idler)->where('salon_id', $salonId)->where('aktif', 1)->count();
        if ($bulunan !== count($idler)) {
            throw new GecersizIslemException($ad.' bu işletmeye ait değil veya silinmiş');
        }
    }

    /** Musteri bu salonun portfoyunde mi (hizli satis). */
    public function musteriOlmali(int $userId, int $salonId): void
    {
        $var = DB::table('musteri_portfoy')->where('user_id', $userId)->where('salon_id', $salonId)->exists();
        if (! $var) {
            throw new GecersizIslemException('Müşteri bu işletmeye ait değil');
        }
    }

    /** Personel bu salonun personeli mi (hizli satis). */
    public function personelOlmali(?int $personelId, int $salonId): void
    {
        if (! $personelId) {
            return;
        }
        if (! DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Personel bu işletmeye ait değil');
        }
    }

    /** Hizmet bu salonun sundugu hizmetlerden mi (sarf recetesi). */
    public function hizmetOlmali(int $hizmetId, int $salonId): void
    {
        if (! DB::table('salon_sunulan_hizmetler')->where('hizmet_id', $hizmetId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Hizmet bu işletmede tanımlı değil');
        }
    }
}
