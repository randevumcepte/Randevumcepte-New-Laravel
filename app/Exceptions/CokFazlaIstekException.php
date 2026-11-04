<?php

namespace App\Exceptions;

/** Kotuye kullanim siniri asildi (429). */
class CokFazlaIstekException extends ApiException
{
    public function __construct(string $mesaj = 'Çok fazla deneme yapıldı. Lütfen daha sonra tekrar deneyiniz.')
    {
        parent::__construct($mesaj, 429);
    }
}
