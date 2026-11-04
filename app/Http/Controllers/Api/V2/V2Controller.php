<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
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
     * Istekteki salon_id + KAPSAM + YETKI (OR-gate) tek adimda. $yetkiler = [] → yalniz kapsam.
     *
     * @param  array<string>|string  $yetkiler
     * @return array{0:int,1:int} [yetkili id, salon id]
     */
    protected function salonDenetle(Request $request, array|string $yetkiler): array
    {
        $user = $this->kullanici();
        $salonId = $this->salonId($request);
        $yetkiler === []
            ? app(GuvenlikKapisi::class)->kapsamDenetle($user->id, $salonId)   // salonun her uyesi (yetki anahtari aranmaz)
            : app(GuvenlikKapisi::class)->denetle($user->id, $salonId, $yetkiler);

        return [(int) $user->id, $salonId];
    }

    /** Istekteki zorunlu salon_id (yoksa 422). Kapsam denetimi cagirana aittir. */
    protected function salonId(Request $request): int
    {
        $salonId = $request->integer('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }

        return $salonId;
    }

    /**
     * Salona bagli olmayan uclarin (kabuk, profil) baglam salonu: salon_id verildiyse kapsam
     * icinde olmali (degilse 403), verilmediyse kullanicinin ilk salonu.
     */
    protected function baglamSalonu(Request $request): int
    {
        $salonlar = app(KapsamServisi::class)->kullaniciSalonlari($this->kullanici()->id);
        if (! $salonlar) {
            throw new YetkisizErisimException('Erişebileceğiniz bir işletme bulunamadı');
        }
        $salonId = $request->integer('salon_id');
        if ($salonId <= 0) {
            return (int) $salonlar[0];
        }
        if (! in_array($salonId, $salonlar, true)) {
            throw new YetkisizErisimException('Bu işletmeye erişim yetkiniz yok');
        }

        return $salonId;
    }

    /** Bu istegi yapan oturumun token id'si (legacy-passport guard'i koyar). */
    protected function aktifTokenId(): ?string
    {
        return request()->attributes->get('oauth_access_token_id');
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
