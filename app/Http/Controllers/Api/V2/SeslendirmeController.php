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
 * Google TTS ucretli: salon kapsami + kullanan ekranlarin yetkisi (OR-gate) + kullanici/salon basina dakika ve gun kotasi.
 * Kullanan ekranlar (Flutter): sesli randevu (randevu.olustur), patron asistani (rapor.ciro_kar_gor), reklam sihirbazi
 * sesli onizleme (pazarlama.kampanya_yonet). Web paneli (public/js) bu ucu kullanmaz.
 *
 *   POST /seslendir {salon_id, metin, ses?} → {basarili, url} | {basarili:false, hata}  (uretilemezse 200: istemci cihaz sesine duser)
 *                                             kota asilirsa 429
 *   GET  /ses/{ad}.mp3?expires=&signature=
 */
class SeslendirmeController extends V2Controller
{
    private const MAKS_METIN = 4000;

    private const IMZA_DAKIKA = 60;

    /** Seslendirmeyi kullanan ekranlarin yetkileri (OR-gate). */
    private const YETKI = ['randevu.olustur', 'rapor.ciro_kar_gor', 'pazarlama.kampanya_yonet'];

    /** Kullanici + salon basina kota: [saniye => istek]. Onbellekten donen (Google'a gitmeyen) istek de sayilir. */
    private const KOTA = [60 => 20, 86400 => 300];

    public function uret(Request $request, SeslendirmeServisi $servis)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);

        $metin = trim((string) $request->input('metin', ''));
        if ($metin === '') {
            throw new GecersizIslemException('Boş metin.');
        }
        $this->kotaDenetle('seslendir', $uid, $salonId, self::KOTA);
        @ignore_user_abort(true);
        @set_time_limit(30);
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
