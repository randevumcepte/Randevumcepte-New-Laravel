<?php

namespace App\Services\Stok\Tanim;

use App\Models\UrunKategorisi;
use App\Services\Stok\StokSunumu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Urun kategorileri (sira, ad'a gore). */
class KategoriServisi extends TanimServisi
{
    public function kaynak(): string
    {
        return 'kategori';
    }

    protected function logOneki(): string
    {
        return 'urun_kategori';
    }

    protected function model(): string
    {
        return UrunKategorisi::class;
    }

    protected function kurallar(): array
    {
        return [
            'ad'   => ['required', 'string', 'max:120'],
            'ikon' => ['nullable', 'string', 'max:50'],
            'renk' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{3,8}$/'],
            'sira' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function sorgu(int $salonId, array $filtre): Builder
    {
        return parent::sorgu($salonId, $filtre)->reorder()->orderBy('sira')->orderBy('ad');
    }

    protected function hazirla(int $salonId, Model $m, array $veri): void
    {
        $m->sira = (int) ($m->sira ?? 0);
    }

    protected function sunum(Model $m): array
    {
        return StokSunumu::kategori($m);
    }

    protected function etiket(Model $m): string
    {
        return (string) $m->ad;
    }
}
