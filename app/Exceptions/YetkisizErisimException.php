<?php

namespace App\Exceptions;

/** Yetki veya kapsam (isletme/sube) ihlali → HTTP 403. */
class YetkisizErisimException extends ApiException
{
    public function __construct(string $mesaj = 'Bu işlem için yetkiniz yok')
    {
        parent::__construct($mesaj, 403);
    }
}
