<?php

namespace App\Services\Santral;

use App\Domain\Santral\SantralErisim;
use App\Exceptions\YetkisizErisimException;
use App\Models\Personeller;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Support\Facades\DB;

/**
 * Santral uclarinin ortak giris denetimi: kimlik + kapsam + santral ozelligi +
 * rol/dahili cozumu. Her santral ucu bunu cagirir (controller'da tekrarlanmaz).
 */
class SantralErisimServisi
{
    public function __construct(
        private GuvenlikKapisi $kapi,
        private KapsamServisi $kapsam,
        private YetkiServisi $yetki,
    ) {}

    /** @throws YetkisizErisimException kapsam disi salon, santrali kapali salon */
    public function coz(int $yetkiliId, int $salonId): SantralErisim
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);

        if (! DB::table('salonlar')->where('id', $salonId)->value('santral_aktif')) {
            throw new YetkisizErisimException('Santral bu işletmede etkin değil');
        }

        $roller = $this->kapsam->salonRolleri($yetkiliId, $salonId);
        $personel = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)
            ->first(['dahili_no', 'dahili_no_webrtc']);

        return new SantralErisim(
            // GuvenlikKapisi::yoneticiDenetle ile ayni kural: rol 5 (personel) degilse yonetici.
            yonetici: ! in_array(5, $roller, true),
            kendiDahilileri: self::dahililer($personel ? [$personel] : []),
            telefonGor: $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.telefon_gor'),
        );
    }

    /** Ayar ekranlari: yalniz yonetici (eski santral sayfasi rol < 5). */
    public function yoneticiZorunlu(int $yetkiliId, int $salonId): SantralErisim
    {
        $erisim = $this->coz($yetkiliId, $salonId);
        if (! $erisim->yonetici) {
            throw new YetkisizErisimException('Santral ayarlarını yönetme yetkiniz yok');
        }

        return $erisim;
    }

    /**
     * Personel kayitlarindan tekil dahili listesi (once dahili_no, sonra WebRTC dahilisi).
     * Personel WebRTC softphone VEYA fiziksel dahiliyle gorusebilir; ikisi de sayilir.
     *
     * @param iterable<object> $personeller
     * @return array<int,string>
     */
    public static function dahililer(iterable $personeller): array
    {
        $liste = [];
        foreach ($personeller as $p) {
            foreach ([$p->dahili_no ?? null, $p->dahili_no_webrtc ?? null] as $d) {
                $d = (string) preg_replace('/\D/', '', (string) $d);
                if ($d !== '') {
                    $liste[] = $d;
                }
            }
        }

        return array_values(array_unique($liste));
    }
}
