<?php

namespace App\Http\Middleware;

use App\Services\Panel\RandevuModulu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Randevu modülü sayfa kapısı (web grubu). Modül host'unda izinsiz bir v2 sayfası açılırsa Randevu
 * Takvimi'ne döner; şube korunur. Eskide Özet → Takvim yönlendirmesi ve "yetkisizerisim" sayfaları.
 * Asıl veri kapısı API'dedir (RandevuModuluApiKapisi); bu katman kullanıcıyı boş ekrana düşürmez.
 */
class RandevuModuluSayfaKapisi
{
    private const HEDEF = '/v2/takvim';

    public function __construct(private RandevuModulu $modul) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->is('v2/*') || $this->modul->sayfaIzinli($request->path())) {
            return $next($request);
        }
        $sube = $request->query('sube') ?: $request->route('sube');

        return redirect(self::HEDEF.(is_numeric($sube) ? '?sube='.(int) $sube : ''));
    }
}
