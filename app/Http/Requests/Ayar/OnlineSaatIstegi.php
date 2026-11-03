<?php

namespace App\Http\Requests\Ayar;

/**
 * PUT /ayar/online-saatler  body (v1 onlineRandevuSaatKaydet ile ayni bicim):
 *   {salon_id, kisitlama_aktif, gunluk_limit, gunler: {"1": ["10:00", ...]}, istisnalar: [{tarih, tip, bas?, bit?}]}
 */
class OnlineSaatIstegi extends AyarIstegi
{
    protected array $jsonAlanlar = ['gunler', 'istisnalar'];

    protected function alanKurallari(): array
    {
        return [
            'kisitlama_aktif'    => ['required', 'boolean'],
            'gunluk_limit'       => ['nullable', 'integer', 'min:0', 'max:1000'],
            'gunler'             => ['present', 'array'],
            'gunler.*'           => ['array'],
            'gunler.*.*'         => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'istisnalar'         => ['present', 'array', 'max:366'],
            'istisnalar.*.tarih' => ['required', 'date_format:Y-m-d'],
            'istisnalar.*.tip'   => ['required', 'in:kapali,ozel'],
            'istisnalar.*.bas'   => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'istisnalar.*.bit'   => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ];
    }

    public function veri(): array
    {
        return [
            'kisitlama_aktif' => $this->boolean('kisitlama_aktif'),
            'gunluk_limit'    => $this->input('gunluk_limit'),
            'gunler'          => (array) $this->input('gunler', []),
            'istisnalar'      => (array) $this->input('istisnalar', []),
        ];
    }
}
