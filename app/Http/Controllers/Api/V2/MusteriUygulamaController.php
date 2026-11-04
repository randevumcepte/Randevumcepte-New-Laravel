<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;

/**
 * Musteri uygulamasi uclarinin tabani ('auth:api', users). Kimlik token'dan gelir;
 * gövdedeki user_id YOK SAYILIR (eski v1 uclari kimliksizdi).
 */
abstract class MusteriUygulamaController extends Controller
{
    protected function musteriId(): int
    {
        $u = auth('api')->user();
        if (! $u) {
            throw new YetkisizErisimException('Giriş yapmalısınız.');
        }

        return (int) $u->id;
    }
}
