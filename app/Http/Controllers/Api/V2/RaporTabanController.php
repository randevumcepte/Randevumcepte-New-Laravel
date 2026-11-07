<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Rapor\RaporDonemi;
use App\Exceptions\YetkisizErisimException;
use App\Models\PersonelYetkiAyari;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rapor + kasa controller'larinin ortak tabani — donem ve liste istegi ayristirma,
 * yonetici (rol 5 degil) kapisi TEK yerde. Kimlik/kapsam/yetki V2Controller::salonDenetle.
 */
abstract class RaporTabanController extends V2Controller
{
    /**
     * salon_id → kapsam + yetki (OR). yonetici=true: rol 5 (personel) hesaplari reddedilir
     * (eski panelde personelmi() ile kapatilan ekranlar).
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function salonYetki(Request $r, array|string $yetki, bool $yonetici = false): array
    {
        [$uid, $salonId] = $this->salonDenetle($r, $yetki);
        if ($yonetici) {
            app(GuvenlikKapisi::class)->yoneticiDenetle($uid, $salonId);
        }

        return [$uid, $salonId];
    }

    /**
     * Mobil yolun kapisi: kimlik + kapsam + yetki (OR). Ozel kaydi olmayan Sekreter de gecer: rapor anahtarlari web'de ona
     * kapali (YetkiSabitleri::sekreterKapaliAnahtarlar), eski mobil ise bu ekranlari acik tutuyordu. Ozel kayit varsa kayit gecerlidir.
     *
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function mobilYetki(Request $r, array|string $yetki): array
    {
        [$uid, $salonId] = $this->salonDenetle($r, []);
        foreach ((array) $yetki as $anahtar) {
            if ($this->yetkiVar($uid, $salonId, $anahtar)) {
                return [$uid, $salonId];
            }
        }
        if ($this->kayitsizSekreter($uid, $salonId)) {
            return [$uid, $salonId];
        }

        throw new YetkisizErisimException('Bu işlem için yetkiniz yok');
    }

    private function kayitsizSekreter(int $uid, int $salonId): bool
    {
        if (! in_array(YetkiSabitleri::ROL_SEKRETER, app(KapsamServisi::class)->salonRolleri($uid, $salonId), true)) {
            return false;
        }
        $personelId = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $uid)->value('id');

        return ! $personelId || ! PersonelYetkiAyari::where('personel_id', $personelId)->where('salon_id', $salonId)->exists();
    }

    /** ?donem=&baslangic=&bitis= (bkz. RaporDonemi). */
    protected function donem(Request $r, string $varsayilan = 'ay'): RaporDonemi
    {
        return RaporDonemi::coz($r->input('donem'), $r->input('baslangic'), $r->input('bitis'), $varsayilan);
    }

    /** ListeIstegi::dizi() — sayfalama / arama / siralama. */
    protected function liste(Request $r, array $siralanabilir, ?string $varsayilanSirala, int $boy = 25): array
    {
        return ListeIstegi::istekten($r, $boy, $siralanabilir, $varsayilanSirala)->dizi();
    }
}
