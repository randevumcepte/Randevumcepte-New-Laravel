<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\MusteriHesap\MusteriResimServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Musterinin islem fotograflari ('auth:api'). Kimlik token'dan; baskasinin fotografina erisilemez.
 *
 *   GET    /musteri-uygulama/resimler?app_bundle=&salon_id=
 *   POST   /musteri-uygulama/resimler (multipart musteriresim, salon_id?, not?, app_bundle?)
 *   DELETE /musteri-uygulama/resimler {path}
 *   PATCH  /musteri-uygulama/resimler/not {path, not?, salon_id?}
 */
class MusteriResimController extends MusteriUygulamaController
{
    public function __construct(private MusteriResimServisi $servis) {}

    public function liste(Request $request)
    {
        return ApiCevap::basarili($this->servis->liste($this->musteriId(), $request->input('app_bundle') ?: null, $request->integer('salon_id') ?: null));
    }

    public function ekle(Request $request)
    {
        $g = $request->validate(['salon_id' => ['nullable', 'integer'], 'not' => ['nullable', 'string', 'max:1000'], 'app_bundle' => ['nullable', 'string', 'max:190']]);

        return ApiCevap::basarili(
            $this->servis->ekle($this->musteriId(), $request->file('musteriresim'), isset($g['salon_id']) ? (int) $g['salon_id'] : null, $g['not'] ?? null),
            'Fotoğraf eklendi', 201,
        );
    }

    public function sil(Request $request)
    {
        $g = $request->validate(['path' => ['required', 'string', 'max:500']]);
        $this->servis->sil($this->musteriId(), $g['path']);

        return ApiCevap::basarili(['deleted' => $g['path']], 'Fotoğraf silindi');
    }

    public function notYaz(Request $request)
    {
        $g = $request->validate(['path' => ['required', 'string', 'max:500'], 'not' => ['nullable', 'string', 'max:1000'], 'salon_id' => ['nullable', 'integer']]);

        return ApiCevap::basarili($this->servis->notYaz($this->musteriId(), $g['path'], $g['not'] ?? null, isset($g['salon_id']) ? (int) $g['salon_id'] : null));
    }
}
