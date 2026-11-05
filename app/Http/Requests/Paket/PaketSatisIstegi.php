<?php

namespace App\Http\Requests\Paket;

use App\Http\Requests\V2Istegi;

/**
 * POST /paket/satis — secilen paketleri musteriye sat (adisyon acar, tahsilat almaz).
 * kalemler[].seans / fiyat verilmezse katalog degeri; fiyat farki yetki ister (servis).
 */
class PaketSatisIstegi extends V2Istegi
{
    protected array $jsonAlanlar = ['kalemler'];

    protected function alanKurallari(): array
    {
        return [
            'musteri_id'          => ['required', 'integer', 'min:1'],
            'personel_id'         => ['nullable', 'integer', 'min:1'],
            'tarih'               => ['nullable', 'date_format:Y-m-d'],
            'kalemler'            => ['required', 'array', 'min:1', 'max:50'],
            'kalemler.*.paket_id' => ['required', 'integer', 'min:1'],
            'kalemler.*.seans'    => ['nullable', 'integer', 'min:0', 'max:1000'],
            'kalemler.*.fiyat'    => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'kalemler.*.hizmetler'   => ['nullable', 'array', 'max:50'],
            'kalemler.*.hizmetler.*' => ['integer', 'min:1'],
            'idempotency_key'     => ['required', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'kalemler' => 'Paket', 'kalemler.*.paket_id' => 'Paket', 'kalemler.*.seans' => 'Seans sayısı',
            'kalemler.*.fiyat' => 'Fiyat', 'kalemler.*.hizmetler' => 'Hizmetler', 'tarih' => 'Satış tarihi',
        ] + parent::attributes();
    }
}
