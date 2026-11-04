<?php

namespace App\Exceptions;

/** Bağlı harici servise (ör. WhatsApp köprüsü) ulaşılamadı → HTTP 502. */
class HariciServisException extends ApiException
{
    public function __construct(string $mesaj = 'Servise ulaşılamıyor')
    {
        parent::__construct($mesaj, 502);
    }
}
