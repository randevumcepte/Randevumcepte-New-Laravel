<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Jobs\BildirimKuyrugu\SmsTopluGonderIsi;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\IsDagitici;
use RuntimeException;

/** Toplu SMS'in durum=0 satirlari; yeni kayitlar durum=4 ile sms:kuyruk-isle'ye gider. Istisna yalniz dagitim oncesi olur. */
class SmsTopluIsleyici implements BildirimIsleyici
{
    public function __construct(private IsDagitici $dagitici) {}

    public function isle(object $kayit, array $payload): void
    {
        $telefonlar = array_values(array_filter((array) ($payload['telefonlar'] ?? [])));
        if (! $telefonlar || trim((string) ($payload['mesaj'] ?? '')) === '') {
            throw new RuntimeException('sms_toplu: alici/mesaj yok');
        }

        $this->dagitici->calistir(new SmsTopluGonderIsi((int) $kayit->salon_id, $telefonlar, (string) $payload['mesaj'],
            (int) ($payload['tur'] ?? 4), (string) ($payload['baslik'] ?? 'Toplu SMS')));
    }
}
