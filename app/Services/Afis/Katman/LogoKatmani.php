<?php

namespace App\Services\Afis\Katman;

use App\Domain\Afis\AfisIcerigi;

/**
 * Sol üst logo kutusu. Logo beyaz kenarlarından kırpılıp kareye ortalanır; logo yoksa /
 * okunamazsa işletme adının baş harfi basılır (eski afisLogoCiz ile aynı).
 */
final class LogoKatmani implements AfisKatmani
{
    private const X = 102;

    private const Y = 62;

    private const KUTU = 102;

    private const IC_BOSLUK = 4;

    private const BEYAZ_ESIK = 200;       // her kanalı bundan açık piksel "boş" sayılır

    private const KENAR_PAYI = 1.16;      // kırpılan içeriğin çevresine bırakılan pay

    private const HARF_RENK = [0x7C, 0x4A, 0x5A];

    public function ciz(\GdImage $tuval, AfisIcerigi $icerik): void
    {
        $logo = $icerik->logoDosyasi !== null ? $this->yukle($icerik->logoDosyasi) : null;
        $logo === null ? $this->basHarf($tuval, $icerik) : $this->yerlestir($tuval, $logo);
    }

    private function yukle(string $yol): ?\GdImage
    {
        $tur = @getimagesize($yol)[2] ?? null;
        $gorsel = match ($tur) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($yol),
            IMAGETYPE_PNG  => @imagecreatefrompng($yol),
            IMAGETYPE_GIF  => @imagecreatefromgif($yol),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($yol) : false,
            default        => false,
        };
        if (! $gorsel) {
            return null;
        }
        // Paletli görselde imagecolorat renk değil palet indeksi döner → kırpma bozulur.
        if (! imageistruecolor($gorsel)) {
            imagepalettetotruecolor($gorsel);
        }

        return $gorsel;
    }

    private function yerlestir(\GdImage $tuval, \GdImage $logo): void
    {
        [$kx, $ky, $kenar] = $this->icerikKaresi($logo);

        $ic = self::KUTU - self::IC_BOSLUK * 2;
        $kare = imagecreatetruecolor($ic, $ic);
        imagefilledrectangle($kare, 0, 0, $ic, $ic, imagecolorallocate($kare, 255, 255, 255));
        // Kare görselin dışına taşabilir (pay + kare oranı); taşan kısım siyah değil beyaz kalsın
        // diye kaynak görsel sınırına kırpılır, hedef aynı oranla ölçeklenir.
        $olcek = $ic / max(1, $kenar);
        $x0 = max(0, $kx);
        $y0 = max(0, $ky);
        $x1 = min(imagesx($logo), $kx + $kenar);
        $y1 = min(imagesy($logo), $ky + $kenar);
        imagecopyresampled(
            $kare, $logo,
            (int) round(($x0 - $kx) * $olcek), (int) round(($y0 - $ky) * $olcek), $x0, $y0,
            (int) round(($x1 - $x0) * $olcek), (int) round(($y1 - $y0) * $olcek), $x1 - $x0, $y1 - $y0,
        );
        imagecopy($tuval, $kare, self::X + self::IC_BOSLUK, self::Y + self::IC_BOSLUK, 0, 0, $ic, $ic);
    }

    /**
     * Beyaz olmayan içeriğin sınır kutusunu (~200x200 örneklemle) bulur ve onu ortalayan
     * kareyi döner.
     *
     * @return array{0:int, 1:int, 2:int} [x, y, kenar]
     */
    private function icerikKaresi(\GdImage $logo): array
    {
        $g = imagesx($logo);
        $y = imagesy($logo);
        [$minX, $minY, $maxX, $maxY] = [$g, $y, 0, 0];
        $adimX = max(1, intdiv($g, 200));
        $adimY = max(1, intdiv($y, 200));

        for ($py = 0; $py < $y; $py += $adimY) {
            for ($px = 0; $px < $g; $px += $adimX) {
                $rgb = imagecolorat($logo, $px, $py);
                if ((($rgb >> 16) & 0xFF) < self::BEYAZ_ESIK || (($rgb >> 8) & 0xFF) < self::BEYAZ_ESIK || ($rgb & 0xFF) < self::BEYAZ_ESIK) {
                    [$minX, $maxX] = [min($minX, $px), max($maxX, $px)];
                    [$minY, $maxY] = [min($minY, $py), max($maxY, $py)];
                }
            }
        }
        if ($maxX <= $minX || $maxY <= $minY) {
            [$minX, $minY, $maxX, $maxY] = [0, 0, $g, $y];
        }

        $kenar = (int) (max($maxX - $minX, $maxY - $minY) * self::KENAR_PAYI);

        return [(int) (($minX + $maxX - $kenar) / 2), (int) (($minY + $maxY - $kenar) / 2), $kenar];
    }

    private function basHarf(\GdImage $tuval, AfisIcerigi $icerik): void
    {
        $font = AfisYazi::font(AfisYazi::BOLD);
        if ($font === null) {
            return;
        }
        $yarim = self::KUTU / 2;
        AfisYazi::ortalaCiz($tuval, $font, self::KUTU * 0.5, $icerik->basHarf(), self::X + $yarim, self::Y + $yarim, imagecolorallocate($tuval, ...self::HARF_RENK));
    }
}
