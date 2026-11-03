<?php

namespace App\Services\Stok\Tanim;

use App\Domain\Telefon\TelefonNumarasi;
use App\Models\Tedarikci;
use App\Services\Stok\StokSunumu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Tedarikciler — yonetimi ayri yetki (urun.tedarikci_yonet). */
class TedarikciServisi extends TanimServisi
{
    public function kaynak(): string
    {
        return 'tedarikci';
    }

    public function yazmaYetkisi(): string
    {
        return 'urun.tedarikci_yonet';
    }

    protected function model(): string
    {
        return Tedarikci::class;
    }

    protected function kurallar(): array
    {
        return [
            'ad'       => ['required', 'string', 'max:200'],
            'telefon'  => ['nullable', 'string', 'max:30'],
            'vergi_no' => ['nullable', 'string', 'max:30'],
            'email'    => ['nullable', 'email', 'max:150'],
            'adres'    => ['nullable', 'string', 'max:1000'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function hazirla(int $salonId, Model $m, array $veri): void
    {
        if (array_key_exists('telefon', $veri)) {
            $m->telefon = TelefonNumarasi::kanonik($veri['telefon']) ?: null;
        }
    }

    protected function sorgu(int $salonId, array $filtre): Builder
    {
        return parent::sorgu($salonId, $filtre)->reorder()->orderBy('ad');
    }

    protected function sunum(Model $m): array
    {
        return StokSunumu::tedarikci($m);
    }

    protected function etiket(Model $m): string
    {
        return (string) $m->ad;
    }
}
