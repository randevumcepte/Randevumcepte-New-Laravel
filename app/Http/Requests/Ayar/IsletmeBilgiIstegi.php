<?php

namespace App\Http\Requests\Ayar;

use App\Domain\Ayar\AyarSozluk;

/**
 * PATCH /ayar/isletme — KISMI guncelleme (gonderilmeyen alan degismez).
 * Eski web / v1 alan adlari da kabul edilir (gecis gunu istemci degismeden calissin):
 *   isletme_adi → salon_adi · isletme_turu → salon_turu_id · isletme_adres → adres ·
 *   isletme_telefon → telefon_1 · isletme_aciklama → aciklama · instagram_url → instagram_sayfa ·
 *   facebook_url → facebook_sayfa · vergi_tc_no → vergi_no · seo_description → meta_description
 */
class IsletmeBilgiIstegi extends AyarIstegi
{
    protected function prepareForValidation(): void
    {
        $this->eskiAdlar([
            'isletme_adi' => 'salon_adi', 'isletme_turu' => 'salon_turu_id', 'isletme_adres' => 'adres',
            'isletme_telefon' => 'telefon_1', 'isletme_aciklama' => 'aciklama', 'instagram_url' => 'instagram_sayfa',
            'facebook_url' => 'facebook_sayfa', 'vergi_tc_no' => 'vergi_no', 'seo_description' => 'meta_description',
        ]);
    }

    protected function alanKurallari(): array
    {
        $metin = fn (int $maks) => ['sometimes', 'nullable', 'string', 'max:'.$maks];

        return [
            'salon_adi'           => ['sometimes', 'required', 'string', 'max:255'],
            'telefon_1'           => ['sometimes', 'required', 'string', 'max:30'],
            'salon_turu_id'       => ['sometimes', 'nullable', 'integer', 'exists:salon_turu,id'],
            'adres'               => $metin(1000),
            'whatsapp'            => $metin(30),
            'aciklama'            => $metin(AyarSozluk::ACIKLAMA_MAKS),
            'instagram_sayfa'     => $metin(255),
            'facebook_sayfa'      => $metin(255),
            'vergi_adi'           => $metin(255),
            'vergi_no'            => $metin(20),
            'vergi_dairesi'       => $metin(255),
            'vergi_adresi'        => $metin(1000),
            'kdv_orani'           => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'meta_description'    => $metin(500),
            'anahtar_kelimeler'   => ['sometimes', 'array', 'max:'.AyarSozluk::ANAHTAR_KELIME_MAKS],
            'anahtar_kelimeler.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function veri(): array
    {
        return $this->validated();
    }
}
