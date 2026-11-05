<?php

namespace App\Http\Requests\Paket;

use App\Http\Requests\V2Istegi;

/**
 * POST /paket · PATCH /paket/{id}
 *
 * hizmetler iki bicimde kabul edilir (ikisi de [{hizmet_id, seans?, fiyat?}]'e cevrilir):
 *   - [{hizmet_id, seans, fiyat}]  hizmet basina seans/fiyat (Flutter PaketHizmetleri.toJson)
 *   - [3, 7]                       yalniz hizmet id'leri (web coklu secim; fiyat/seans paket duzeyinde)
 */
class PaketKaydetIstegi extends V2Istegi
{
    protected array $jsonAlanlar = ['hizmetler'];

    protected bool $guncellemedeKismi = true;

    protected function alanKurallari(): array
    {
        return [
            'paket_adi'             => ['required', 'string', 'max:255'],
            'hizmetler'             => ['required', 'array', 'min:1', 'max:50'],
            'hizmetler.*.hizmet_id' => ['required', 'integer', 'min:1', 'distinct'],
            'hizmetler.*.seans'     => ['nullable', 'integer', 'min:0', 'max:1000'],
            'hizmetler.*.fiyat'     => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'miktar'                => ['nullable', 'integer', 'min:0', 'max:1000'],
            'fiyat'                 => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'sure'                  => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sinirsiz'              => ['nullable', 'in:0,1'],
            'gecerlilik_sayi'       => ['nullable', 'required_if:sinirsiz,1', 'integer', 'min:1', 'max:120'],
            'gecerlilik_birim'      => ['nullable', 'required_with:gecerlilik_sayi', 'in:ay,yil'],
            'gunluk_limit'          => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'paket_adi' => 'Paket adı', 'hizmetler' => 'Hizmet', 'hizmetler.*.hizmet_id' => 'Hizmet',
            'hizmetler.*.seans' => 'Hizmet seansı', 'hizmetler.*.fiyat' => 'Hizmet fiyatı',
            'miktar' => 'Seans', 'fiyat' => 'Fiyat', 'sure' => 'Süre',
            'sinirsiz' => 'Paket türü', 'gecerlilik_sayi' => 'Geçerlilik süresi', 'gecerlilik_birim' => 'Süre birimi', 'gunluk_limit' => 'Günlük limit',
        ] + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $h = $this->input('hizmetler');
        if (is_array($h)) {
            $this->merge(['hizmetler' => array_values(array_map(fn ($x) => is_array($x) ? $x : ['hizmet_id' => $x], $h))]);
        }
        // Bos metin alanlari null (form bos gonderince 0 sanilmasin).
        $this->merge(array_map(fn ($v) => $v === '' ? null : $v, $this->only(['miktar', 'fiyat', 'sure', 'gecerlilik_sayi', 'gecerlilik_birim', 'gunluk_limit'])));
    }
}
