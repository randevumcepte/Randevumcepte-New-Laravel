<?php

namespace App\Services\Bildirim;

use App\Domain\Bildirim\CihazSahibi;
use App\Exceptions\GecersizIslemException;
use App\Services\Yetki\KapsamServisi;
use Illuminate\Support\Facades\DB;

/**
 * Doğrulanmış kimlikten kutu ve cihaz sahibi üretir. personel_id / user_id istemciden ALINMAZ
 * (eski v1 uçları kimliksizdi; adres çubuğundaki id ile başkasının kutusu okunabiliyordu).
 */
class BildirimKutusuFabrikasi
{
    /** Ana uygulama tüm markaları görür; marka süzgeci uygulanmaz. */
    public const ANA_UYGULAMA = 'com.randevumcepte.randevumcepte';

    private const PERSONEL_ROLU = 5;

    public function __construct(private KapsamServisi $kapsam) {}

    public function personel(int $yetkiliId, int $salonId): PersonelKutusu
    {
        return new PersonelKutusu($salonId, $this->personelId($yetkiliId, $salonId), $yetkiliId);
    }

    /**
     * Marka uygulamasında markanın TÜM şubeleri; aksi halde verilen şube; ikisi de yoksa tümü.
     * Bundle hiçbir salonla eşleşmezse süzgeç uygulanmaz (eskiyle aynı).
     */
    public function musteri(int $userId, ?int $salonId, ?string $bundle): MusteriKutusu
    {
        if ($bundle && $bundle !== self::ANA_UYGULAMA) {
            $marka = DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($marka) {
                return new MusteriKutusu($userId, $marka, true, $salonId);
            }
        }

        return new MusteriKutusu($userId, $salonId ? [$salonId] : null, false, $salonId);
    }

    public function personelCihazi(int $yetkiliId, int $salonId): CihazSahibi
    {
        $personelId = $this->personelId($yetkiliId, $salonId);
        if (! $personelId) {
            throw new GecersizIslemException('Bu işletmede personel kaydınız bulunamadı');
        }
        $personelRolu = in_array(self::PERSONEL_ROLU, $this->kapsam->salonRolleri($yetkiliId, $salonId), true);

        return CihazSahibi::personel($personelId, $salonId, ! $personelRolu);
    }

    private function personelId(int $yetkiliId, int $salonId): ?int
    {
        $id = DB::table('salon_personelleri')->where('yetkili_id', $yetkiliId)->where('salon_id', $salonId)->value('id');

        return $id ? (int) $id : null;
    }
}
