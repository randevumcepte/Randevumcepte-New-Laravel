<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;

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
