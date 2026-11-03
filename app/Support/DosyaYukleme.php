<?php

namespace App\Support;

use App\Exceptions\GecersizIslemException;
use Illuminate\Http\UploadedFile;

/**
 * Yuklenen dosyayi ESKI monolitin public/ altindaki bir dizine kaydetme — TEK KAYNAK
 * (Form harici belge, isletme logosu, galeri gorselleri).
 *
 * Kurallar: dosya secili + gecerli, izinli uzanti, boyut tavani; ad = ASCII'ye cevrilmis
 * kok (en fazla 60) + rastgele ek → ayni adli dosyalar birbirini EZMEZ (eski logo
 * yuklemesindeki ezme hatasi burada yok).
 */
final class DosyaYukleme
{
    /** Gorsel uzantilari (logo / galeri). */
    public const GORSEL_UZANTILARI = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * @param  list<string>  $uzantilar
     * @return string Kaydedilen dosya adi (dizin haric)
     *
     * @throws GecersizIslemException
     */
    public static function kaydet(?UploadedFile $dosya, string $dizin, array $uzantilar, int $maksKb, string $varsayilanKok = 'dosya'): string
    {
        if (! $dosya || ! $dosya->isValid()) {
            throw new GecersizIslemException('Yüklenecek dosyayı seçiniz.');
        }
        $uzanti = strtolower($dosya->getClientOriginalExtension());
        if (! in_array($uzanti, $uzantilar, true)) {
            throw new GecersizIslemException('Desteklenmeyen dosya türü. İzinli: '.implode(', ', $uzantilar));
        }
        if ($dosya->getSize() > $maksKb * 1024) {
            throw new GecersizIslemException('Dosya en fazla '.round($maksKb / 1024, 1).' MB olabilir.');
        }

        $kok = pathinfo($dosya->getClientOriginalName(), PATHINFO_FILENAME);
        $kok = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $kok) ?: $varsayilanKok), '-') ?: $varsayilanKok;
        $ad = mb_substr($kok, 0, 60).'-'.bin2hex(random_bytes(6)).'.'.$uzanti;

        $dosya->move($dizin, $ad);

        return $ad;
    }

    /**
     * Base64 gorsel ("data:image/png;base64,..." ya da ciplak base64) kaydetme — eski mobil
     * istemcilerin (Flutter v1 reklam gorseli) gonderdigi bicim. Icerik GERCEKTEN gorsel olmali
     * (getimagesizefromstring); uzanti MIME'dan belirlenir, istemcinin beyanina guvenilmez.
     *
     * @param  list<string>  $uzantilar
     * @return string Kaydedilen dosya adi (dizin haric)
     *
     * @throws GecersizIslemException
     */
    public static function base64GorselKaydet(string $veri, string $dizin, array $uzantilar, int $maksKb, string $kok = 'gorsel'): string
    {
        $ham = base64_decode(preg_replace('#^data:[^;,]+;base64,#', '', trim($veri)), true);
        if ($ham === false || $ham === '') {
            throw new GecersizIslemException('Görsel çözülemedi.');
        }
        if (strlen($ham) > $maksKb * 1024) {
            throw new GecersizIslemException('Dosya en fazla '.round($maksKb / 1024, 1).' MB olabilir.');
        }
        $bilgi = @getimagesizefromstring($ham);
        $uzanti = match ($bilgi[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif', default => null,
        };
        if (! $uzanti || ! in_array($uzanti, $uzantilar, true)) {
            throw new GecersizIslemException('Desteklenmeyen dosya türü. İzinli: '.implode(', ', $uzantilar));
        }
        if (! is_dir($dizin)) {
            @mkdir($dizin, 0755, true);
        }
        $ad = $kok.'-'.bin2hex(random_bytes(6)).'.'.$uzanti;
        if (file_put_contents(rtrim($dizin, '/').'/'.$ad, $ham) === false) {
            throw new GecersizIslemException('Görsel kaydedilemedi.');
        }

        return $ad;
    }

    /**
     * Gorseli en-boy oranini koruyarak $yukseklik px'e kucultur (eski Intervention
     * resize(null, 720) karsiligi). GD yoksa / gorsel zaten kucukse / okunamazsa
     * dosyaya dokunmaz — yukleme asla bu adim yuzunden basarisiz olmaz.
     */
    public static function gorselKucult(string $yol, int $yukseklik): void
    {
        if (! function_exists('imagecreatefromstring') || ! is_file($yol)) {
            return;
        }
        $bilgi = @getimagesize($yol);
        if (! $bilgi || $bilgi[1] <= $yukseklik) {
            return;
        }
        $kaynak = @imagecreatefromstring((string) file_get_contents($yol));
        if (! $kaynak) {
            return;
        }
        $genislik = max(1, (int) round($bilgi[0] * $yukseklik / $bilgi[1]));
        $hedef = imagescale($kaynak, $genislik, $yukseklik);
        if ($hedef) {
            match ($bilgi[2]) {
                IMAGETYPE_PNG  => imagepng($hedef, $yol),
                IMAGETYPE_WEBP => imagewebp($hedef, $yol, 85),
                IMAGETYPE_GIF  => imagegif($hedef, $yol),
                default        => imagejpeg($hedef, $yol, 85),
            };
        }
    }

    /** Eski public/ altindaki dosyayi guvenli sil (yoksa sessiz). */
    public static function sil(string $kokDizin, ?string $goreliYol): void
    {
        $goreli = ltrim(preg_replace('#^/?public/#', '', (string) $goreliYol), '/');
        if ($goreli === '' || str_contains($goreli, '..')) {
            return;
        }
        $yol = rtrim($kokDizin, '/').'/'.$goreli;
        if (is_file($yol)) {
            @unlink($yol);
        }
    }
}
