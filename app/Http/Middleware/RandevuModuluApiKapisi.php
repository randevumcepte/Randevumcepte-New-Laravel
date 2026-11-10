<?php

namespace App\Http\Middleware;

use App\Services\Panel\RandevuModulu;
use App\Support\ApiCevap;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Randevu modülü API kapısı (api grubu). Modül host'undan gelen istekte kapalı işletme uçları
 * (config('panel.randevu_modulu.kapali_api'): satış, tahsilat, raporlama, çağrı merkezi) 403 döner:
 * {basarili:false, mesaj, veri:{kod:'randevu_modulu'}}. Menüden gizlemek tek başına yetmez; veri
 * API'den de alınamamalı. Müşteri (auth:api) ve sistem yönetimi uçları kapsam dışıdır.
 */
class RandevuModuluApiKapisi
{
    public const KOD = 'randevu_modulu';

    public function __construct(private RandevuModulu $modul) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v2/*')
            && LisansKapisi::isletmeRotasi($request->route()?->gatherMiddleware() ?? [])
            && $this->modul->apiKapali(Str::after($request->path(), 'api/v2/'))) {
            return ApiCevap::hata('Bu bölüm randevu modülünde kullanılamaz', 403, ['kod' => self::KOD]);
        }

        return $next($request);
    }
}
