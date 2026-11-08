<?php

namespace App\Services\Paket;

use App\Domain\Paket\PaketGecerlilik;
use App\Domain\Paket\PaketTutari;
use App\Models\PaketHizmetler;
use App\Models\Paketler;

/**
 * Paket JSON sozlesmesi — web (/v2/paketler) ve Flutter AYNI bicimi okur.
 *
 * Alan adlari Flutter'in mevcut Paket / PaketHizmetleri modelleriyle uyumlu:
 *   id, paket_adi, aktif, miktar, satis_seans, seans_metni, fiyat, sure, hizmetler[{hizmet_id, seans, fiyat, hizmet{id, hizmet_adi}}]
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
            // Satista varsayilan hak (bolge basina, PaketTutari::satisSeansi) ve liste metni ("8" ya da "8 / 5 / 3").
            'satis_seans'   => PaketTutari::satisSeansi($p->miktar, $hizmetler),
            'seans_metni'   => PaketTutari::seansMetni($p->miktar, $hizmetler),
            'sure'          => $p->sure !== null && $p->sure !== '' ? (int) $p->sure : null,
            'sinirsiz'      => (int) $p->sinirsiz,
            'gecerlilik_sayi'  => $p->gecerlilik_sayi !== null ? (int) $p->gecerlilik_sayi : null,
            'gecerlilik_birim' => $p->gecerlilik_birim,
            'gecerlilik_metni' => PaketGecerlilik::sureMetni($p->gecerlilik_sayi, $p->gecerlilik_birim),
            'gunluk_limit'     => $p->gunluk_limit !== null ? (int) $p->gunluk_limit : null,
            'hizmetler'     => $hizmetler,
            'hizmet_adlari' => implode(', ', array_filter(array_column($hizmetler, 'hizmet_adi'))),
        ];
    }
}
