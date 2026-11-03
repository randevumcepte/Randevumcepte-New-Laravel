<?php

namespace App\Http\Requests\Ayar;

/**
 * PUT /ayar/musteri-indirimleri  body: {salon_id, sadik:{acik, yuzde}, aktif:{acik, yuzde}}.
 * Eski form adlari da kabul edilir: sadik_acikkapali + sadik_musteri_indirimi, aktif_acikkapali + aktif_musteri_indirimi.
 */
class MusteriIndirimIstegi extends AyarIstegi
{
    protected function prepareForValidation(): void
    {
        foreach (['sadik', 'aktif'] as $tur) {
            if (! $this->has($tur) && $this->has($tur.'_musteri_indirimi')) {
                $this->merge([$tur => [
                    'acik'  => $this->has($tur.'_acikkapali'),
                    'yuzde' => $this->input($tur.'_musteri_indirimi'),
                ]]);
            }
        }
    }

    protected function alanKurallari(): array
    {
        $kurallar = [];
        foreach (['sadik', 'aktif'] as $tur) {
            $kurallar[$tur] = ['required', 'array'];
            $kurallar[$tur.'.acik'] = ['required', 'boolean'];
            $kurallar[$tur.'.yuzde'] = ['nullable', "required_if_accepted:{$tur}.acik", 'numeric', 'min:0', 'max:100'];
        }

        return $kurallar;
    }

    public function veri(): array
    {
        return $this->only(['sadik', 'aktif']);
    }
}
