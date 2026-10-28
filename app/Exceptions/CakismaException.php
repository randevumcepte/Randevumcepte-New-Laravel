<?php

namespace App\Exceptions;

/** Idempotency çakışması / yeniden işleme → HTTP 409. */
class CakismaException extends ApiException
{
    public function __construct(string $mesaj = 'Bu istek zaten işleniyor veya işlendi')
    {
        parent::__construct($mesaj, 409);
    }
}
