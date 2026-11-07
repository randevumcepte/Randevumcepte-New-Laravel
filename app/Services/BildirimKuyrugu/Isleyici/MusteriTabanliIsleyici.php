<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** musteri_kayit ve kara_liste_bildirim ortak basi: eskideki gibi musteri yoksa hata (yeniden denenir). */
abstract class MusteriTabanliIsleyici implements BildirimIsleyici
{
    final public function isle(object $kayit, array $payload): void
    {
        $musteri = DB::table('users')->where('id', (int) ($payload['user_id'] ?? 0))->first();
        if (! $musteri) {
            throw new RuntimeException('Musteri bulunamadi: '.($payload['user_id'] ?? '-'));
        }

        $this->musteriIsle($kayit, $payload, $musteri);
    }

    abstract protected function musteriIsle(object $kayit, array $payload, object $musteri): void;
}
