<?php

namespace App\Providers;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Yetki karar motoru — request boyunca tek ornek (cache paylasimi icin singleton).
        $this->app->singleton(\App\Services\Yetki\YetkiServisi::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->legacyPassportGuardKaydet();
    }

    /**
     * "legacy-passport" guard sürücüsü.
     *
     * Neden özel guard? Eski monolit Passport 4 ile üretilmiş token kullanıyor;
     * paylaşılan oauth_clients.provider = NULL ve aynı id birden fazla kullanıcı
     * tablosunda farklı kişilere denk gelebiliyor (örn. users#3 != isletmeyetkilileri#3).
     * Passport 13'ün hasValidProvider kontrolü NULL-provider client'ı reddediyor.
     *
     * Çözüm: Token'ı league/oauth2-server ResourceServer ile DOĞRULA (kriptografik
     * doğrulama Passport 4→13 arası sorunsuz çalışıyor — kanıtlandı), ardından
     * kullanıcıyı guard'ın KENDİ provider'ından (users / isletmeyetkilileri) id ile
     * çöz. Böylece her uç, eski monolitteki route-guard seçimiyle birebir eşleşir:
     *   - musteri uclari          -> provider 'users'
     *   - isletme (tahsilat vb.)  -> provider 'isletmeyetkilileri'
     */
    protected function legacyPassportGuardKaydet(): void
    {
        Auth::extend('legacy-passport', function ($app, $name, array $config) {
            $provider = Auth::createUserProvider($config['provider'] ?? null);

            return new RequestGuard(function ($request) use ($provider) {
                try {
                    // Illuminate isteğini PSR-7'ye çevir (ResourceServer PSR bekler)
                    $guzzle = new HttpFactory();
                    $psrFactory = new PsrHttpFactory($guzzle, $guzzle, $guzzle, $guzzle);
                    $psr = $psrFactory->createRequest($request);

                    // Kriptografik + revocation doğrulaması (imza, exp, oauth_access_tokens.revoked)
                    $validated = app(ResourceServer::class)->validateAuthenticatedRequest($psr);

                    $userId = $validated->getAttribute('oauth_user_id');

                    // Kullaniciyi guard'in KENDI tablosundan coz (yanlis tablo riskini keser)
                    return $userId ? $provider->retrieveById($userId) : null;
                } catch (\Throwable $e) {
                    // Gecersiz/suresi dolmus/iptal edilmis token -> kimliksiz
                    return null;
                }
            }, $app['request'], $provider);
        });
    }
}
