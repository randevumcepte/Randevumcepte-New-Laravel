<?php

namespace App\Support\Pdf;

/**
 * Tek bir JPEG görseli kenar boşluksuz kaplayan TEK sayfalık PDF (PDF 1.4) üretir.
 * Bağımlılıksız: JPEG akışı DCTDecode ile olduğu gibi gömülür (yeniden sıkıştırma yok).
 *
 * Varsayılan sayfa A4 dikey (595.28 x 841.89 pt). Görsel sayfaya gerdirilir — oranı
 * sayfayla aynı olan görsel verin (afiş arka planı A4 oranında).
 */
final class GorselPdf
{
    public const A4_DIKEY = [595.28, 841.89];

    /** @param  array{0: float, 1: float}  $sayfa  pt cinsinden [genişlik, yükseklik] */
    public static function jpegden(string $jpeg, array $sayfa = self::A4_DIKEY): string
    {
        $bilgi = @getimagesizefromstring($jpeg);
        if (! $bilgi || $bilgi[2] !== IMAGETYPE_JPEG) {
            throw new \InvalidArgumentException('GorselPdf yalnız JPEG kabul eder');
        }
        [$gpx, $ypx] = $bilgi;
        $renk = ($bilgi['channels'] ?? 3) === 1 ? '/DeviceGray' : '/DeviceRGB';
        [$g, $y] = array_map(fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'), $sayfa);
        $cizim = "q {$g} 0 0 {$y} 0 0 cm /G Do Q";

        $nesneler = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$g} {$y}] /Resources << /XObject << /G 4 0 R >> >> /Contents 5 0 R >>",
            "<< /Type /XObject /Subtype /Image /Width {$gpx} /Height {$ypx} /ColorSpace {$renk} /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream",
            '<< /Length '.strlen($cizim)." >>\nstream\n{$cizim}\nendstream",
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $konumlar = [];
        foreach ($nesneler as $i => $govde) {
            $konumlar[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$govde}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($nesneler) + 1)."\n0000000000 65535 f \n";
        foreach ($konumlar as $k) {
            $pdf .= sprintf("%010d 00000 n \n", $k);
        }

        return $pdf."trailer\n<< /Size ".(count($nesneler) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
