<?php

namespace App\Services\Afis;

use App\Domain\Afis\AfisIcerigi;
use App\Exceptions\GecersizIslemException;
use App\Services\Afis\Katman\AfisKatmani;
use App\Services\Afis\Katman\BaslikKatmani;
use App\Services\Afis\Katman\LogoKatmani;
use App\Services\Afis\Katman\QrKatmani;
use App\Services\Afis\Katman\TelefonAdiKatmani;
use App\Support\Pdf\GorselPdf;
use Illuminate\Support\Facades\Cache;

/**
 * Afiş görseli üretimi: arka plan + katmanlar (sırayla) → JPEG; PDF = aynı JPEG'in A4 sayfası.
 * Önizleme ve PDF TEK render'ı paylaşır (aynı görüntü, tek önbellek kaydı).
 *
 * Önbellek 'file' store'unda (MB'lık ikili veri paylaşılan DB'nin cache tablosuna yazılmaz);
 * anahtar = içerik parmak izi + arka plan mtime + RENDER_SURUMU (katman geometrisi değişince artır).
 */
class AfisUretici
{
    private const RENDER_SURUMU = 1;

    private const ARKA_PLAN = 'afis-bg.png';

    /** @var list<AfisKatmani> çizim sırası */
    private array $katmanlar;

    public function __construct(QrKatmani $qr, LogoKatmani $logo, BaslikKatmani $baslik, TelefonAdiKatmani $telefon)
    {
        $this->katmanlar = [$qr, $logo, $baslik, $telefon];
    }

    public function jpeg(AfisIcerigi $icerik): string
    {
        $arkaPlan = $this->arkaPlanYolu();
        $anahtar = 'v2_afis:'.self::RENDER_SURUMU.':'.sha1($icerik->parmakIzi().'|'.filemtime($arkaPlan));

        return Cache::store('file')->remember($anahtar, (int) config('afis.onbellek_dk', 1440) * 60, fn () => $this->ciz($icerik, $arkaPlan));
    }

    public function pdf(AfisIcerigi $icerik): string
    {
        return GorselPdf::jpegden($this->jpeg($icerik), GorselPdf::A4_DIKEY);
    }

    private function ciz(AfisIcerigi $icerik, string $arkaPlan): string
    {
        $tuval = @imagecreatefrompng($arkaPlan);
        if (! $tuval) {
            throw new GecersizIslemException('Afiş şablonu okunamadı');
        }
        imagealphablending($tuval, true);

        foreach ($this->katmanlar as $katman) {
            $katman->ciz($tuval, $icerik);
        }

        ob_start();
        imagejpeg($tuval, null, (int) config('afis.jpeg_kalite', 92));

        return (string) ob_get_clean();
    }

    private function arkaPlanYolu(): string
    {
        if (! function_exists('imagecreatefrompng') || ! function_exists('imagettftext')) {
            throw new GecersizIslemException('Sunucuda görsel üretimi (GD + FreeType) kullanılamıyor');
        }
        $yol = rtrim((string) config('afis.kaynak_dizin'), '/').'/'.self::ARKA_PLAN;
        if (! is_file($yol)) {
            throw new GecersizIslemException('Afiş şablonu bulunamadı');
        }

        return $yol;
    }
}
