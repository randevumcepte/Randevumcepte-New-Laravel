<?php

namespace App\Services\Oturum;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/** Istekteki Bearer token'in imza, sure ve iptal dogrulamasi (guard'lar ve mobil bayrak suzgeci ortak). */
class IstekTokeni
{
    /** @throws \League\OAuth2\Server\Exception\OAuthServerException gecersiz, suresi dolmus ya da iptal edilmis */
    public function dogrula(Request $istek): ServerRequestInterface
    {
        $fabrika = new HttpFactory();

        return app(ResourceServer::class)->validateAuthenticatedRequest(
            (new PsrHttpFactory($fabrika, $fabrika, $fabrika, $fabrika))->createRequest($istek),
        );
    }
}
