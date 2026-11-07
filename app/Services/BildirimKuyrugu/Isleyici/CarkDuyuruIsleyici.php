<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Jobs\BildirimKuyrugu\CarkDuyuruGonderIsi;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\IsDagitici;

/** Carkifelek "Musterilere Duyur". */
class CarkDuyuruIsleyici implements BildirimIsleyici
{
    public function __construct(private IsDagitici $dagitici) {}

    public function isle(object $kayit, array $payload): void
    {
        $this->dagitici->calistir(new CarkDuyuruGonderIsi((int) $kayit->salon_id, (string) ($payload['baslik'] ?? ''), (string) ($payload['mesaj'] ?? '')));
    }
}
