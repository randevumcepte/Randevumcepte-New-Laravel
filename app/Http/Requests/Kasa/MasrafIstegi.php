<?php

namespace App\Http\Requests\Kasa;

use App\Http\Requests\V2Istegi;

/**
 * POST /masraf, PATCH /masraf/{id}. Eski form kurali: YENI normal masrafta kategori + harcayan
 * + odeme yontemi zorunlu; personel giderinde kategori / odeme bos olabilir (varsayilan atanir).
 * Duzenlemede harcayan/kategori bos kalabilir — kasadan para cekme kayitlari ("Kasa", kategorisiz).
 * mobil=1 (yalniz imzali mobil token): eski mobil sifir tutari ve uzun metni kabul ediyordu, ekran yalniz bosluga bakar.
 */
class MasrafIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        $yeni = $this->route('id') === null;
        $normal = $yeni && ! $this->boolean('personel_gideri') ? 'required' : 'nullable';
        $mobil = $this->boolean('mobil');

        return [
            'tarih'              => ['required', 'date_format:Y-m-d'],
            'tutar'              => ['required', 'numeric', $mobil ? 'min:0' : 'gt:0', 'max:99999999'],
            'personel_gideri'    => ['sometimes', 'boolean'],
            'harcayan_id'        => [$yeni ? 'required' : 'nullable', 'integer', 'min:1'],
            'masraf_kategori_id' => [$normal, 'integer', 'exists:masraf_kategorileri,id'],
            'odeme_yontemi_id'   => [$normal, 'integer', 'exists:odeme_yontemleri,id'],
            'aciklama'           => $mobil ? ['nullable', 'string'] : ['nullable', 'string', 'max:500'],
            'notlar'             => $mobil ? ['sometimes', 'nullable', 'string'] : ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key'    => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
