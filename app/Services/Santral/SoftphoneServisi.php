<?php

namespace App\Services\Santral;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\YetkisizErisimException;
use App\Models\Personeller;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use Illuminate\Support\Facades\DB;

/**
 * Panel header'indaki WebRTC softphone'un sunucu tarafi — eski layout_isletmeadmin
 * (#webtelefon + gizli santral_dahili_no/sifre input'lari) + /isletmeyonetim/musteriarama.
 *
 * Softphone YALNIZCA: salonda santral etkin VE kullanicinin bu salondaki personel kaydinda
 * WebRTC dahilisi (dahili_no_webrtc) tanimli. SIP sifresi yalniz bu kosulda, yalniz sahibine
 * doner (eskiden her sayfanin HTML'ine gomuluyordu).
 */
class SoftphoneServisi
{
    public function __construct(
        private GuvenlikKapisi $kapi,
        private KapsamServisi $kapsam,
        private DakikaServisi $dakika,
    ) {}

    /** Softphone kurulumu; kullanilamiyorsa null (header telefonu cizmez). */
    public function yapilandirma(int $yetkiliId, int $salonId): ?array
    {
        $personel = $this->webrtcPersoneli($yetkiliId, $salonId);
        if (! $personel) {
            return null;
        }
        $ayar = config('santral.softphone');
        $taban = rtrim((string) config('panel.eski_panel_url'), '/');

        return [
            'dahili'     => (string) $personel->dahili_no_webrtc,
            'sifre'      => (string) ($personel->dahili_sifre_webrtc ?? ''),
            'sip_sunucu' => $ayar['sip_sunucu'],
            'wss_url'    => $ayar['wss_url'],
            // Eski panel: personel rolu (5) yalniz gelen cagri karsilar, numara cevirme alani gizli.
            'arama_yapabilir' => ! in_array(5, $this->kapsam->salonRolleri($yetkiliId, $salonId), true),
            'sesler' => [
                'zil'     => $taban.$ayar['zil_yolu'],
                'geri_zil' => $taban.$ayar['geri_zil_yolu'],
            ],
        ];
    }

    /** Salon havuzunda kalan konusma dakikasi (header rozeti). Santral okunamazsa null. */
    public function kalanDakika(int $yetkiliId, int $salonId): ?float
    {
        $this->erisimZorunlu($yetkiliId, $salonId);

        return $this->dakika->havuzKalan($salonId);
    }

    /**
     * Gelen/giden cagridaki numaranin salon portfoyundeki musteri adi (yoksa null → istemci
     * numarayi gosterir). Yalniz ad doner (KVKK minimizasyonu).
     */
    public function arayanAdi(int $yetkiliId, int $salonId, string $numara): ?string
    {
        $this->erisimZorunlu($yetkiliId, $salonId);

        $kanonik = TelefonNumarasi::kanonik($numara);
        if (strlen(ltrim($kanonik, '+')) < 7) {
            return null; // dahili / kisa numara
        }
        $ad = DB::table('musteri_portfoy')
            ->join('users', 'musteri_portfoy.user_id', '=', 'users.id')
            ->where('musteri_portfoy.salon_id', $salonId)
            ->whereIn('users.cep_telefon', TelefonNumarasi::aramaVaryantlari($kanonik))
            ->orderByDesc('musteri_portfoy.aktif')
            ->value('users.name');

        return $ad !== null && trim((string) $ad) !== '' ? trim((string) $ad) : null;
    }

    private function erisimZorunlu(int $yetkiliId, int $salonId): void
    {
        if (! $this->webrtcPersoneli($yetkiliId, $salonId)) {
            throw new YetkisizErisimException('Bu işletmede web telefonunuz tanımlı değil');
        }
    }

    private function webrtcPersoneli(int $yetkiliId, int $salonId): ?object
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);

        if (! DB::table('salonlar')->where('id', $salonId)->value('santral_aktif')) {
            return null;
        }
        $personel = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)
            ->first(['dahili_no_webrtc', 'dahili_sifre_webrtc']);

        return $personel && trim((string) $personel->dahili_no_webrtc) !== '' ? $personel : null;
    }
}
