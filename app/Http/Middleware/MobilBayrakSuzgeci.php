<?php

namespace App\Http\Middleware;

use App\Services\Oturum\IstekTokeni;
use App\Services\Oturum\MobilBayraklar;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Kimlik guard'indan gecmeyen rotalarda mobil bayrak kurali: token yoksa veya gecersizse bayrak etkisizdir, imzali mobil
 * token varsa kalir. auth: rotalarinda is tokenGuard'indir; orada token ikinci kez dogrulanmaz.
 */
class MobilBayrakSuzgeci
{
    public function __construct(private IstekTokeni $token) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->guardRotasi($request)) {
            $this->uygula($request);
        }

        return $next($request);
    }

    private function guardRotasi(Request $request): bool
    {
        return collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($m) => is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:')));
    }

    private function uygula(Request $request): void
    {
        try {
            MobilBayraklar::uygula($request, $request->bearerToken() ? (array) $this->token->dogrula($request)->getAttribute('oauth_scopes') : []);
        } catch (Throwable) {
            MobilBayraklar::temizle($request);
        }
    }
}
