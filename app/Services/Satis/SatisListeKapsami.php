<?php

namespace App\Services\Satis;

use App\Models\Personeller;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\Yetki\IslemYetkileri;

/**
 * Satis listelerinin (web /satislar, mobil satis listesi, mobil gunluk paket/urun satislari) TEK kapisi ve personel
 * kisiti. Web ve mobil ayni kurali kullanir (2026-11-12):
 *   - kimlik + kapsam + IslemYetkileri::SATIS_GOR (VEYA)
 *   - 'satis.tum_satis_gor' yoksa yalniz kendi satislari (musteri kartinda 'musteri.gecmis_satis_gor' kisiti kaldirir)
 *   - 'personel.kapali_adisyon_gizle': personel kaydi olan kullanici kapali adisyonlari goremez
 */
class SatisListeKapsami
{
    public function __construct(private GuvenlikKapisi $kapi, private YetkiServisi $yetki) {}

    /**
     * @return array{kisit:?int, personel_id:?int, kapali_gizle:bool} kisit: zorunlu personel filtresi (null = kisit yok)
     */
    public function denetle(int $yetkiliId, int $salonId, bool $musteriKarti): array
    {
        $this->kapi->denetle($yetkiliId, $salonId, IslemYetkileri::SATIS_GOR);

        $personelId = Personeller::where('yetkili_id', $yetkiliId)->where('salon_id', $salonId)->value('id');
        $personelId = $personelId ? (int) $personelId : null;
        $kisit = null;
        if ($personelId && ! $this->var($yetkiliId, $salonId, 'satis.tum_satis_gor')
            && ! ($musteriKarti && $this->var($yetkiliId, $salonId, 'musteri.gecmis_satis_gor'))) {
            $kisit = $personelId;
        }

        return [
            'kisit'        => $kisit,
            'personel_id'  => $personelId,
            'kapali_gizle' => $personelId !== null && $this->var($yetkiliId, $salonId, 'personel.kapali_adisyon_gizle'),
        ];
    }

    private function var(int $yetkiliId, int $salonId, string $anahtar): bool
    {
        return $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $anahtar);
    }
}
