<?php

namespace App\Providers;

use App\Domain\Oturum\TokenTipi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Oturum\IstekTokeni;
use App\Services\Oturum\MobilBayraklar;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Yetki karar motoru — request boyunca tek ornek (cache paylasimi icin singleton).
        $this->app->singleton(\App\Services\Yetki\YetkiServisi::class);

        // WhatsApp oturum köprüsü sağlayıcısı (servisler arayüze bağlı).
        $this->app->bind(\App\Services\Whatsapp\Kopru\WhatsappKoprusu::class, \App\Services\Whatsapp\Kopru\WhatsmeowKoprusu::class);
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
        // Auth::extend kapanisi AuthManager'a baglar: icinde $this bu sinif degildir.
        $tokenGuard = $this->tokenGuard(...);

        // Yalniz sayisal subject: sistem yoneticisi token'i ("sy:<id>") isletme/musteri ucuna giremez.
        Auth::extend('legacy-passport', fn ($app, $name, array $config) => $tokenGuard(
            $app,
            $config,
            fn (string $sub) => ctype_digit($sub) ? (int) $sub : null,
            fn ($kullanici) => true,
            $config['hesap_turu'] ?? null,
        ));

        // Yalniz "sy:<id>" subject + aktif hesap: pasif edilen yonetici token'i suresi dolmadan da duser.
        Auth::extend('sistem-passport', fn ($app, $name, array $config) => $tokenGuard(
            $app,
            $config,
            fn (string $sub) => ErisimTokenServisi::sistemSubjecttenId($sub),
            fn ($kullanici) => (int) $kullanici->aktif === 1,
        ));
    }

    /**
     * @param  callable(string):?int  $idCoz  token subject'i bu guard'a ait degilse null
     * @param  callable(object):bool  $kabul  cozulen kullanici icin son kontrol
     * @param  ?string  $hesapTuru  doluysa token'in hesap tipi buna uymali (TokenTipi)
     */
    private function tokenGuard($app, array $config, callable $idCoz, callable $kabul, ?string $hesapTuru = null): RequestGuard
    {
        $provider = Auth::createUserProvider($config['provider'] ?? null);

        $guard = new RequestGuard(function ($request) use ($provider, $idCoz, $kabul, $hesapTuru) {
            try {
                // Kriptografik + revocation doğrulaması (imza, exp, oauth_access_tokens.revoked)
                $validated = app(IstekTokeni::class)->dogrula($request);
                $kapsam = (array) $validated->getAttribute('oauth_scopes');
                MobilBayraklar::uygula($request, $kapsam);

                $id = $idCoz((string) $validated->getAttribute('oauth_user_id'));
                if ($id === null) {
                    return null;
                }
                $tokenId = $validated->getAttribute('oauth_access_token_id');
                $tip = TokenTipi::coz($kapsam);
                if ($hesapTuru !== null && ! $this->tokenTipiUygun($tip, $hesapTuru, $tokenId)) {
                    return null;
                }
                // Oturumu tanimak icin (sifre degisiminde bu oturum korunur).
                $request->attributes->set('oauth_access_token_id', $tokenId);
                $request->attributes->set('hesap_tipi', $tip);

                // Kullaniciyi guard'in KENDI tablosundan coz (yanlis tablo riskini keser)
                $kullanici = $provider->retrieveById($id);

                return $kullanici && $kabul($kullanici) ? $kullanici : null;
            } catch (\Throwable $e) {
                // Gecersiz/suresi dolmus/iptal edilmis token -> kimliksiz
                MobilBayraklar::temizle($request);

                return null;
            }
        }, $app['request'], $provider);
        // Bayraklar guncel istekten silinir: guard onbellekte kalsa da istek nesnesi guncel kalir.
        $app->refresh('request', $guard, 'setRequest');

        return $guard;
    }

    private function tokenTipiUygun(?string $tip, string $beklenen, ?string $tokenId): bool
    {
        $tipsizKabul = (bool) config('oturum.tipsiz_token_kabul');
        // Ad yalniz gerektiginde okunur: tipli token ve acik gecis ek sorgu yapmaz.
        $ad = $tip === null && ! $tipsizKabul ? DB::table('oauth_access_tokens')->where('id', $tokenId)->value('name') : null;

        return TokenTipi::kabul($tip, $beklenen, $tipsizKabul, $ad, (string) config('oturum.kopru_token_adi'));
    }
}
