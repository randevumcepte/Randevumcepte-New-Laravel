<?php

namespace App\Http\Requests\OnGorusme;

use App\Http\Requests\V2Istegi;

/**
 * POST /ongorusme/{id}/satis-yapildi — görüşmeyi satışa dönüştür (adisyon açar, tahsilat almaz).
 * Alanlar nedene göre anlamlıdır (paket: seans + fiyat · ürün: adet · hizmet: seans + fiyat);
 * ilgisiz alan yok sayılır, fiyat farkı yetkisi serviste denetlenir.
 * Eski istemci adları: baslangic_tarihi → tarih · seans_sayisi → seans · urun_adedi → adet.
 */
class OnGorusmeSatisIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        return [
            'tarih'       => ['nullable', 'date_format:Y-m-d'],
            'personel_id' => ['nullable', 'integer', 'min:1'],
            'fiyat'       => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'seans'       => ['nullable', 'integer', 'min:0', 'max:1000'],
            'adet'        => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function attributes(): array
    {
        return ['tarih' => 'Satış tarihi', 'personel_id' => 'Satıcı', 'fiyat' => 'Fiyat', 'seans' => 'Seans sayısı', 'adet' => 'Adet']
            + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->eskiAdlar(['baslangic_tarihi' => 'tarih', 'seans_sayisi' => 'seans', 'urun_adedi' => 'adet']);
        $this->merge(array_map(fn ($v) => $v === '' || $v === 'null' ? null : $v, $this->only(['tarih', 'personel_id', 'fiyat', 'seans', 'adet'])));
    }
}
