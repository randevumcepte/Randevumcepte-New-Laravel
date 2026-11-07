<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Randevular;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use RuntimeException;

/** randevu_id'ye bagli turlerin ortak basi: eskideki gibi randevu yoksa hata (yeniden denenir). */
abstract class RandevuTabanliIsleyici implements BildirimIsleyici
{
    final public function isle(object $kayit, array $payload): void
    {
        $randevu = Randevular::find($kayit->randevu_id);
        if (! $randevu) {
            throw new RuntimeException('Randevu bulunamadi: '.$kayit->randevu_id);
        }

        $this->randevuIsle($kayit, $payload, $randevu);
    }

    abstract protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void;
}
