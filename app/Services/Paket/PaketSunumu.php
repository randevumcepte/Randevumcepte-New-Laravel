<?php

namespace App\Services\Paket;

use App\Domain\Paket\PaketTutari;
use App\Models\PaketHizmetler;
use App\Models\Paketler;

/**
 * Paket JSON sozlesmesi — web (/v2/paketler) ve Flutter AYNI bicimi okur.
 *
 * Alan adlari Flutter'in mevcut Paket / PaketHizmetleri modelleriyle uyumlu:
 *   id, paket_adi, aktif, miktar, fiyat, sure, hizmetler[{hizmet_id, seans, fiyat, hizmet{id, hizmet_adi}}]
 * fiyat / miktar ETKIN degerdir (PaketTutari) — istemci yeniden hesaplamaz.
 */
final class PaketSunumu
{
    public static function paket(Paketler $p): array
    {
        $hizmetler = $p->hizmetler->map(fn (PaketHizmetler $h) => [
            'hizmet_id'  => (int) $h->hizmet_id,
            'hizmet_adi' => trim((string) $h->hizmet?->hizmet_adi),
            'seans'      => $h->seans !== null ? (int) $h->seans : null,
            'fiyat'      => $h->fiyat !== null ? (float) $h->fiyat : null,
            'hizmet'     => ['id' => (int) $h->hizmet_id, 'hizmet_adi' => trim((string) $h->hizmet?->hizmet_adi)],
        ])->values()->all();
        $etkin = PaketTutari::etkin($p->fiyat, $p->miktar, $hizmetler);

        return [
            'id'            => (int) $p->id,
            'paket_adi'     => (string) $p->paket_adi,
            'aktif'         => (int) $p->aktif,
            'fiyat'         => $etkin['fiyat'],
            'miktar'        => $etkin['seans'],
            'sure'          => $p->sure !== null && $p->sure !== '' ? (int) $p->sure : null,
            'hizmetler'     => $hizmetler,
            'hizmet_adlari' => implode(', ', array_filter(array_column($hizmetler, 'hizmet_adi'))),
        ];
    }
}
