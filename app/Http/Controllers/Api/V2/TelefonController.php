<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Telefon\TelefonUlkeleri;
use App\Http\Controllers\Controller;
use App\Support\ApiCevap;

/**
 * Telefon ulke kodlari API v2 — web (telefon-ulke.js) ve Flutter AYNI listeyi okur.
 *
 * GET /api/v2/telefon/ulkeler  (PUBLIC — kisisel veri icermez; kayit/giris ekranlari da kullanir)
 *   veri: {varsayilan, sik_kullanilan: [iso], ulkeler: [{iso, ad, kod, maske, en_cok, tercih, sifir_kalir}]}
 *   Saklama sozlesmesi: TR → 10 hane "5XXXXXXXXX", yabanci → "+KOD" + ulusal numara
 *   (sunucu her yazmada App\Domain\Telefon\TelefonNumarasi::kanonik uygular).
 */
class TelefonController extends Controller
{
    public function ulkeler()
    {
        return ApiCevap::basarili([
            'varsayilan'     => TelefonUlkeleri::VARSAYILAN,
            'sik_kullanilan' => TelefonUlkeleri::SIK_KULLANILAN,
            'ulkeler'        => TelefonUlkeleri::liste(),
        ])->setPublic()->setMaxAge(86400);
    }
}
