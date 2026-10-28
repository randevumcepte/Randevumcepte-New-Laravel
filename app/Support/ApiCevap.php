<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Standart API v2 cevap zarfi — hem web (Vue) hem Flutter icin KARARLI sozlesme.
 * Her cevap: { basarili: bool, veri: <payload|null>, mesaj: <string|null> }.
 * Hata HTTP kodlari: 401 kimlik, 403 yetki/kapsam, 404 bulunamadi, 422 dogrulama.
 */
class ApiCevap
{
    public static function basarili($veri = null, ?string $mesaj = null, int $kod = 200): JsonResponse
    {
        return response()->json([
            'basarili' => true,
            'veri'     => $veri,
            'mesaj'    => $mesaj,
        ], $kod);
    }

    public static function hata(string $mesaj, int $kod = 400, $veri = null): JsonResponse
    {
        return response()->json([
            'basarili' => false,
            'veri'     => $veri,
            'mesaj'    => $mesaj,
        ], $kod);
    }
}
