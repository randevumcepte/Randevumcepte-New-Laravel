<?php

namespace App\Http\Requests\Stok;

/**
 * POST /stok/hizli-satis. Eski alan adlari da kabul (user_id → musteri_id,
 * odeme_yontemi → odeme_yontemi_id). tahsilat_yap verilmezse true (eski varsayilan).
 * Fiyat istemciden ALINMAZ (katalog fiyati) — sepet.*.birim_fiyat yok sayilir.
 */
class HizliSatisIstegi extends StokIstegi
{
    protected array $jsonAlanlar = ['sepet'];

    protected function alanKurallari(): array
    {
        return [
            'sepet'            => ['required', 'array', 'min:1', 'max:100'],
            'sepet.*.urun_id'  => ['required', 'integer', 'min:1'],
            'sepet.*.miktar'   => ['required', 'integer', 'min:1', 'max:9999'],
            'sepet.*.depo_id'  => ['nullable', 'integer', 'min:1'],
            'musteri_id'       => ['required', 'integer', 'min:1'],
            'personel_id'      => ['nullable', 'integer', 'min:1'],
            'tahsilat_yap'     => ['boolean'],
            'odeme_yontemi_id' => ['required_if_accepted:tahsilat_yap', 'nullable', 'integer', 'exists:odeme_yontemleri,id'],
            'satis_tarihi'     => ['nullable', 'date_format:Y-m-d'],
            'idempotency_key'  => ['required', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge([
            'musteri_id'       => $this->input('musteri_id') ?: $this->input('user_id'),
            'odeme_yontemi_id' => $this->input('odeme_yontemi_id') ?: $this->input('odeme_yontemi'),
            'tahsilat_yap'     => filter_var($this->input('tahsilat_yap', true), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function messages(): array
    {
        return parent::messages() + [
            'sepet.required'                        => 'Sepet boş.',
            'musteri_id.required'                   => 'Müşteri seçilmedi.',
            'odeme_yontemi_id.required_if_accepted' => 'Peşin tahsilat için ödeme yöntemi seçin.',
            'sepet.*.miktar.integer'                => 'Satış miktarı tam sayı olmalı.',
        ];
    }
}
