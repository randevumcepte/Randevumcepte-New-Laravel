<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\Seslendirme\SeslendirmeServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Metni Google TTS ile MP3'e cevirir (eski /seslendir, /ses/{ad}). Eski uc kimliksizdi, uretim artik token ister.
 * Dosya, ses calar bilesen baslik gonderemedigi icin sureli imzali adresle verilir.
 *
 *   POST /seslendir {metin, ses?}      → {basarili, url} | {basarili:false, hata}  (uretilemezse 200: istemci cihaz sesine duser)
 *   GET  /ses/{ad}.mp3?expires=&signature=
 */
class SeslendirmeController extends V2Controller
{
    private const MAKS_METIN = 4000;

    private const IMZA_DAKIKA = 60;

    public function uret(Request $request, SeslendirmeServisi $servis)
    {
        $this->kullanici();
        @ignore_user_abort(true);
        @set_time_limit(30);

        $metin = trim((string) $request->input('metin', ''));
        if ($metin === '') {
            throw new GecersizIslemException('Boş metin.');
        }
        $ses = $request->filled('ses') ? preg_replace('/[^A-Za-z0-9\-]/', '', (string) $request->input('ses')) : null;

        $ad = $servis->uret(mb_substr($metin, 0, self::MAKS_METIN), $ses ?: null);
        if (! $ad) {
            return ApiCevap::basarili(['basarili' => false, 'hata' => 'Ses üretilemedi.']);
        }

        return ApiCevap::basarili([
            'basarili' => true,
            'url' => url(URL::temporarySignedRoute('v2.ses', now()->addMinutes(self::IMZA_DAKIKA), ['ad' => $ad], false)),
        ]);
    }

    public function ses(string $ad, SeslendirmeServisi $servis)
    {
        $yol = $servis->dosyaYolu($ad);
        abort_unless($yol, 404);

        return response()->file($yol, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=3600']);
    }
}
