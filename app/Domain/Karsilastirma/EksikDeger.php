<?php

namespace App\Domain\Karsilastirma;

use RuntimeException;

/** Kontrolun yer tutucusu icin deger verilmedi; kontrol atlanir. */
final class EksikDeger extends RuntimeException {}
