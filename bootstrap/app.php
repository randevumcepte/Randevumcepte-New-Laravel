<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API-first: tum v2 uclari burada. Prefix 'api' (varsayilan) oldugundan
        // gercek URL'ler /api/v2/... olur. Hem web paneli (Vue) hem Flutter
        // mobil uygulamasi AYNI uclari tuketir.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Is kurali ihlallerini TUTARLI zarfa cevir: {basarili:false, veri:null, mesaj}.
        // Istemciler (Flutter + web) her uc icin ayri hata mantigi yazmasin.
        $exceptions->render(function (\App\Exceptions\ApiException $e) {
            return \App\Support\ApiCevap::hata($e->getMessage(), $e->durumKodu);
        });

        // Kimlik dogrulanamadi (guard null / auth middleware) → 401 ayni zarf.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return \App\Support\ApiCevap::hata('Oturum geçersiz veya token hatalı', 401);
            }
        });

        // Dogrulama hatasi → 422 ayni zarf (alan hatalari 'veri'de).
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return \App\Support\ApiCevap::hata('Girdi doğrulanamadı', 422, $e->errors());
            }
        });

        // Bulunamayan rota/kayit → 404 ayni zarf.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return \App\Support\ApiCevap::hata('Kayıt bulunamadı', 404);
            }
        });
    })->create();
