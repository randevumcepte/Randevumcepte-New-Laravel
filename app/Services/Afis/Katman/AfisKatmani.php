<?php

namespace App\Services\Afis\Katman;

use App\Domain\Afis\AfisIcerigi;

/**
 * Afiş arka planının üstüne çizilen tek bir öğe (QR, logo, başlık …). Her katman yalnız
 * kendi geometrisini ve çizimini bilir; yeni öğe = yeni katman sınıfı (üretici değişmez).
 *
 * Koordinatlar arka plan görselinin (afis-bg.png, 1588x2246) pikselleridir.
 */
interface AfisKatmani
{
    public function ciz(\GdImage $tuval, AfisIcerigi $icerik): void;
}
