<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Anket\AnketAyarIstegi;
use App\Services\Anket\AnketAyarServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Reputation Booster ayarları.
 *   GET   /anket/ayarlar?salon_id=
 *   PATCH /anket/ayarlar     → ayarlar + {uyari}
 *   POST  /anket/ayarlar/premium
 */
class AnketAyarController extends AnketTabanController
{
    public function __construct(private AnketAyarServisi $ayar) {}

    public function goster(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, self::YETKI);

        return ApiCevap::basarili($this->ayar->oku($salonId));
    }

    public function kaydet(AnketAyarIstegi $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->ayar->kaydet($salonId, $request->validated(), $uid), 'Ayarlar kaydedildi');
    }

    public function premium(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->ayar->premiumAc($salonId, $uid), 'Reputation Booster Premium açıldı');
    }
}
