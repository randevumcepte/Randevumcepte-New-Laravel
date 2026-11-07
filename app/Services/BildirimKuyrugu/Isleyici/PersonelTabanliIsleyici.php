<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Personeller;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use RuntimeException;

/** payload.personel_id'ye bagli turlerin ortak basi: eskideki gibi personel yoksa hata (yeniden denenir). */
abstract class PersonelTabanliIsleyici implements BildirimIsleyici
{
    final public function isle(object $kayit, array $payload): void
    {
        $personel = Personeller::find((int) ($payload['personel_id'] ?? 0));
        if (! $personel) {
            throw new RuntimeException('Personel bulunamadi: '.($payload['personel_id'] ?? '-'));
        }

        $this->personelIsle($kayit, $payload, $personel);
    }

    abstract protected function personelIsle(object $kayit, array $payload, Personeller $personel): void;
}
