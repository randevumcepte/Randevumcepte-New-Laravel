<?php

namespace App\Exceptions;

/**
 * Is kurali ihlallerini TUTARLI API hata yanitina ceviren taban sinif.
 * bootstrap/app.php withExceptions icinde tek yerde {basarili:false, mesaj}
 * zarfina + $durumKodu'na map'lenir. Boylece istemciler her uc icin ayri
 * hata isleme yazmaz.
 */
abstract class ApiException extends \Exception
{
    public function __construct(string $mesaj, public int $durumKodu)
    {
        parent::__construct($mesaj);
    }
}
