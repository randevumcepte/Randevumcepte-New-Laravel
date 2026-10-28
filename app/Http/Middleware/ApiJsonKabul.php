<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-first guvence: her /api/* istegini "JSON bekliyor" olarak isaretle.
 *
 * Neden: kimliksiz bir istekte Laravel'in auth middleware'i, istek JSON
 * beklemiyorsa (Accept header yoksa) var olmayan 'login' rotasina yonlendirmeye
 * calisir -> RouteNotFoundException -> 500. Bu app'te web/login rotasi yok; tum
 * /api/* yanitlari JSON olmali. Accept header'ini sabitleyerek auth middleware
 * dogrudan 401 (AuthenticationException) akitir, yonlendirme denemez.
 */
class ApiJsonKabul
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
