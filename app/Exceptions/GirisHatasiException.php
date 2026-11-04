<?php

namespace App\Exceptions;

/** Telefon/sifre eslesmedi → HTTP 401. */
class GirisHatasiException extends ApiException
{
    public function __construct(string $mesaj = 'Telefon numarası veya şifre hatalı.')
    {
        parent::__construct($mesaj, 401);
    }
}
