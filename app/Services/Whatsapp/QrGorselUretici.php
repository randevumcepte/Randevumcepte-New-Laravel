<?php

namespace App\Services\Whatsapp;

use App\Domain\Afis\Qr\QrKodlayici;

/**
 * WhatsApp eşleştirme QR'ı → PNG data URI. Eski ekran bu dizeyi görsele çevirmek için
 * api.qrserver.com'a gönderiyordu; dize oturum eşleştirme sırrı olduğundan artık sunucudan çıkmaz.
 * PNG: web <img> ve Flutter Image.memory ek paket olmadan gösterir.
 */
class QrGorselUretici
{
    private const MODUL_PX = 8;

    /** QR standardının zorunlu sessiz bölgesi (modül). */
    private const SESSIZ_BOLGE = 4;

    public function dataUri(string $metin): string
    {
        $qr = QrKodlayici::kodla($metin);
        $kenar = ($qr->boyut() + 2 * self::SESSIZ_BOLGE) * self::MODUL_PX;
        $gorsel = imagecreatetruecolor($kenar, $kenar);
        imagefill($gorsel, 0, 0, imagecolorallocate($gorsel, 255, 255, 255));
        $siyah = imagecolorallocate($gorsel, 0, 0, 0);

        for ($y = 0; $y < $qr->boyut(); $y++) {
            for ($x = 0; $x < $qr->boyut(); $x++) {
                if ($qr->koyuMu($x, $y)) {
                    $sol = ($x + self::SESSIZ_BOLGE) * self::MODUL_PX;
                    $ust = ($y + self::SESSIZ_BOLGE) * self::MODUL_PX;
                    imagefilledrectangle($gorsel, $sol, $ust, $sol + self::MODUL_PX - 1, $ust + self::MODUL_PX - 1, $siyah);
                }
            }
        }

        ob_start();
        imagepng($gorsel);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
