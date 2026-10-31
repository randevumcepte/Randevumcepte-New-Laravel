<?php

namespace App\Exceptions;

/** Is kurali geregi yapilamayan islem (orn. "zaten telafi olusturulmus") → HTTP 422. */
class GecersizIslemException extends ApiException
{
    public function __construct(string $mesaj = 'Bu işlem yapılamaz')
    {
        parent::__construct($mesaj, 422);
    }
}
