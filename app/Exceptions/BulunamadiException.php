<?php

namespace App\Exceptions;

/** Istenen kayit yok (ya da kullanicinin kapsaminda degil) → HTTP 404. */
class BulunamadiException extends ApiException
{
    public function __construct(string $mesaj = 'Kayıt bulunamadı')
    {
        parent::__construct($mesaj, 404);
    }
}
