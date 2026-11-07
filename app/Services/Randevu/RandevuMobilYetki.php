<?php

namespace App\Services\Randevu;

use App\Exceptions\YetkisizErisimException;
use App\Models\Personeller;
use App\Models\PersonelYetkiAyari;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\Yetki\YetkiSabitleri;

/**
 * Randevu uclarinin mobil yolu (mobil=1) icin yetki kurallari: eski mobilde rolun yapabildigi is
 * calismaya devam eder. Kimlik ve salon kapsami her kuralda aranir; web yolu bu sinifa girmez.
 */
class RandevuMobilYetki
{
    private const ONAY = 'randevu.onayla';

    private const DUZENLE_IPTAL = 'randevu.duzenle_iptal';

    public function __construct(
        private GuvenlikKapisi $kapi,
        private KapsamServisi $kapsam,
        private YetkiServisi $yetki,
    ) {}

    /** Eski mobilde yetki aranmayan isler (gelmedi, seans telafisi): salon uyesi yeter. */
    public function salonUyesi(int $yetkiliId, int $salonId): void
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);
    }

    /** Eski kural: kayitta randevu.onayla anahtari hic yoksa Personel rolu disinda aciktir, acikca kapali olan kapali kalir. */
    public function onay(int $yetkiliId, int $salonId): void
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);
        if ($this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, self::ONAY) || ($this->personelDegil($yetkiliId, $salonId) && $this->onayKayittaYok($yetkiliId, $salonId))) {
            return;
        }

        throw new YetkisizErisimException('Bu işlem için yetkiniz yok');
    }

    /** Randevu listesindeki sessiz iptal: menu Personel rolune kapali, eski uc anahtar aramiyordu. */
    public function listeIptali(int $yetkiliId, int $salonId): void
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);
        if (! $this->personelDegil($yetkiliId, $salonId) && ! $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, self::DUZENLE_IPTAL)) {
            throw new YetkisizErisimException('Bu işlem için yetkiniz yok');
        }
    }

    private function personelDegil(int $yetkiliId, int $salonId): bool
    {
        return ! in_array(YetkiSabitleri::ROL_PERSONEL, $this->kapsam->salonRolleri($yetkiliId, $salonId), true);
    }

    private function onayKayittaYok(int $yetkiliId, int $salonId): bool
    {
        $personelId = Personeller::where('yetkili_id', $yetkiliId)->where('salon_id', $salonId)->value('id');
        $ayar = $personelId ? PersonelYetkiAyari::where('personel_id', $personelId)->where('salon_id', $salonId)->first() : null;

        return $ayar && is_array($ayar->ayarlar) && ! array_key_exists(self::ONAY, $ayar->ayarlar);
    }
}
