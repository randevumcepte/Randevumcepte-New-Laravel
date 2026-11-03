<?php

namespace App\Http\Requests\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Services\Ayar\RandevuAyarServisi;
use Illuminate\Validation\Rule;

/**
 * PATCH /ayar/randevu — KISMI guncelleme. Renk alanlari gecersizse servis mevcut rengi korur.
 * v1 takvimRenkSet ({aktif, renk}) de kabul edilir → takvim_tek_renk_aktif / takvim_tek_renk.
 */
class RandevuAyarIstegi extends AyarIstegi
{
    protected array $jsonAlanlar = ['takvim_renk_sirasi'];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->eskiAdlar(['aktif' => 'takvim_tek_renk_aktif', 'renk' => 'takvim_tek_renk']);
    }

    protected function alanKurallari(): array
    {
        $kurallar = [
            'randevu_saat_araligi' => ['sometimes', 'integer', Rule::in(AyarSozluk::SAAT_ARALIKLARI)],
            'randevu_takvim_turu'  => ['sometimes', 'integer', Rule::in(array_column(AyarSozluk::TAKVIM_TURLERI, 'kod'))],
            'takvim_tek_renk'      => ['sometimes', 'nullable', 'string', 'max:7'],
            'takvim_yazi_rengi'    => ['sometimes', 'nullable', 'string', 'max:7'],
            'takvim_renk_sirasi'   => ['sometimes', 'nullable', 'array', 'max:30'],
        ];
        foreach (RandevuAyarServisi::BAYRAKLAR as $b) {
            $kurallar[$b] = ['sometimes', 'boolean'];
        }

        return $kurallar;
    }

    public function veri(): array
    {
        return $this->validated();
    }
}
