<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Yetki\GuvenlikKapisi;
use Illuminate\Http\Request;

/**
 * Tum /api/v2 controller'larinin ortak tabani — kimlik, kayit-bulunamadi ve yazma
 * kill-switch adimlari TEK yerde (her controller'da kopyalanmaz).
 *
 * Hatalar ApiException alt siniflari olarak firlatilir; bootstrap/app.php bunlari
 * standart ApiCevap zarfina ({basarili:false, veri:null, mesaj}) cevirir — web ve
 * Flutter ayni hata sozlesmesini gorur.
 */
abstract class V2Controller extends Controller
{
    /** Yazma kill-switch'inin config alani: config/<alan>.php → yazma_aktif. */
    protected ?string $yazmaAlani = null;

    /** Kill-switch kapaliyken donen 409 mesaji (cagri yerinde ozellestirilebilir). */
    protected string $yazmaKapaliMesaj = 'Gerçek yazma henüz etkin değil';

    /** Giris yapan isletme yetkilisi (legacy-passport). Yoksa 403. */
    protected function kullanici()
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        return $user;
    }

    /**
     * Istekteki salon_id + KAPSAM + YETKI (OR-gate) tek adimda.
     *
     * @param  array<string>|string  $yetkiler
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function salonDenetle(Request $request, array|string $yetkiler): array
    {
        $user = $this->kullanici();
        $salonId = $request->integer('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }
        app(GuvenlikKapisi::class)->denetle($user->id, $salonId, $yetkiler);

        return [(int) $user->id, $salonId];
    }

    /** Kullanicinin bu salonda yetki anahtari var mi (buton/alan gorunurlugu icin; 403 atmaz). */
    protected function yetkiVar(int $yetkiliId, int $salonId, string $anahtar): bool
    {
        return app(\App\Services\Yetki\YetkiServisi::class)->yetkiliYetkiVar($yetkiliId, $salonId, $anahtar);
    }

    /** Kaydin salonu cozulemediyse 404 (kapsam sizintisi yok); cozulduyse salon_id. */
    protected function bulundu(?int $salonId, string $mesaj = 'Kayıt bulunamadı'): int
    {
        if (! $salonId) {
            throw new BulunamadiException($mesaj);
        }

        return $salonId;
    }

    /** Yazma kill-switch'i kapaliysa 409. */
    protected function yazmaAcikMi(?string $mesaj = null): void
    {
        if ($this->yazmaAlani === null) {
            throw new \LogicException(static::class.': $yazmaAlani tanimli degil');
        }
        if (! config($this->yazmaAlani.'.yazma_aktif')) {
            throw new CakismaException($mesaj ?? $this->yazmaKapaliMesaj);
        }
    }
}
