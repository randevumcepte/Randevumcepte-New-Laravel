<?php

namespace App\Http\Requests\Cark;

use App\Http\Requests\V2Istegi;

/**
 * PUT /cark — dilimler + ayarlar + şube seçimi (eski web carkdilimekle / mobil carkAdmin/dilim-kaydet
 * gövdesiyle aynı alan adları; web ve Flutter değişmeden gönderebilir). Kazanan kuralı, dilim
 * sayısı ve tip/değer normalleştirmesi Domain\Cark\DilimSeti'nde (burada yalnız biçim).
 */
class CarkKaydetIstegi extends V2Istegi
{
    protected array $jsonAlanlar = ['dilimler', 'salon_ids'];

    protected function alanKurallari(): array
    {
        return [
            'dilimler'                  => ['required', 'array', 'min:1', 'max:'.config('cark.dilim_max')],
            'dilimler.*.name'           => ['nullable', 'string', 'max:100'],
            'dilimler.*.color'          => ['nullable', 'string', 'max:9'],
            'dilimler.*.probability'    => ['required', 'numeric', 'between:0,100'],
            'dilimler.*.tip'            => ['nullable', 'string', 'max:30'],
            'dilimler.*.deger'          => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'dilimler.*.indirim_tipi'   => ['nullable', 'in:yuzde,tutar'],
            'aktifmi'                   => ['sometimes', 'boolean'],
            'kurallar'                  => ['sometimes', 'nullable', 'string', 'max:5000'],
            'cevirme_araligi_gun'       => ['sometimes', 'integer', 'min:1', 'max:365'],
            'kupon_cark_gecerlilik_gun' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'kupon_puan_gecerlilik_gun' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'salon_ids'                 => ['sometimes', 'nullable', 'array'],
            'salon_ids.*'               => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'dilimler'                  => 'Dilimler',
            'dilimler.*.probability'    => 'Dilim olasılığı',
            'dilimler.*.deger'          => 'Dilim değeri',
            'kurallar'                  => 'Kullanım kuralları',
            'cevirme_araligi_gun'       => 'Çevirme aralığı',
            'kupon_cark_gecerlilik_gun' => 'Çark kuponu geçerlilik süresi',
            'kupon_puan_gecerlilik_gun' => 'Puan kuponu geçerlilik süresi',
        ] + parent::attributes();
    }
}
