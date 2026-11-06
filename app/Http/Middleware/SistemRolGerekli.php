<?php

namespace App\Http\Middleware;

use App\Exceptions\YetkisizErisimException;
use Closure;
use Illuminate\Http\Request;

/** Sistem yonetimi rol kapisi: ->middleware('sistem.rol:super_admin,yonetici'). Yetkiler rotalarda okunur. */
class SistemRolGerekli
{
    public function handle(Request $request, Closure $next, string ...$roller)
    {
        $yonetici = auth('sistemyonetim-api')->user();
        if (! $yonetici || ! in_array($yonetici->rol()->value, $roller, true)) {
            throw new YetkisizErisimException();
        }

        return $next($request);
    }
}
