<?php

namespace App\Domain\Asistan;

/** Mobil uygulamanin iki gorev gorunumu: Asistanim listesi ve ana sayfa ozeti. Metinleri farklidir. */
enum MobilGorevGorunumu: string
{
    case Liste = 'liste';
    case Ozet = 'ozet';
}
