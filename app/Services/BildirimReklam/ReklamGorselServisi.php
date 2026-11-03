<?php

namespace App\Services\BildirimReklam;

use App\Support\AktiviteLog;
use App\Support\DosyaYukleme;
use Illuminate\Http\UploadedFile;

/**
 * Reklam görseli yükleme — eski bildirim_reklam_gorsel (web, base64) + adminGorsel (mobil v1,
 * base64) uçlarının TEK karşılığı. Web multipart dosya gönderir; Flutter base64 göndermeye devam
 * edebilir. Dosya eski monolitin public/reklam_gorselleri dizinine, DB yolu eskiyle aynı biçimde.
 *
 * Eskiden farklı olarak: içerik gerçekten görsel mi denetlenir, boyut tavanı var, ad tahmin edilemez.
 * Yükleme reklamı DEĞİŞTİRMEZ — dönen `yol` kaydet isteğinin `gorsel` alanına konur (eski akış).
 */
class ReklamGorselServisi
{
    /** @return array{yol:string, url:?string} */
    public function yukle(int $salonId, int $yetkiliId, UploadedFile|string $gorsel): array
    {
        $dizin = (string) config('bildirim_reklam.gorsel_dizin');
        $uzantilar = (array) config('bildirim_reklam.gorsel_uzantilari');
        $maksKb = (int) config('bildirim_reklam.gorsel_maks_kb');
        $kok = 'reklam_'.$salonId;

        $ad = $gorsel instanceof UploadedFile
            ? DosyaYukleme::kaydet($gorsel, $dizin, $uzantilar, $maksKb, $kok)
            : DosyaYukleme::base64GorselKaydet($gorsel, $dizin, $uzantilar, $maksKb, $kok);
        $yol = config('bildirim_reklam.gorsel_yol').$ad;

        AktiviteLog::yaz($salonId, $yetkiliId, 'bildirim_reklam_gorsel', 'bildirim_reklam', null, $ad, 'Bildirim reklamı görseli yüklendi');

        return ['yol' => $yol, 'url' => BildirimReklamSunumu::gorselUrl($yol)];
    }
}
