<?php

namespace App\Http\Requests\Musteri;

use App\Domain\Musteri\DogumTarihi;
use App\Domain\Musteri\MusteriSozluk;
use Illuminate\Validation\Rule;

/**
 * POST /musteri · PATCH /musteri/{id}
 *
 * Eski istemci adlari da kabul edilir (gecis gunu HTTP katmani kolay olsun):
 *   musteri_referans → musteri_tipi · cep_telefon → telefon ·
 *   dogum_tarihi_gun/ay/yil (eski web formu) → dogum_tarihi (Y-m-d).
 */
class MusteriKaydetIstegi extends MusteriIstegi
{
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'musteri_tipi' => $this->input('musteri_tipi', $this->input('musteri_referans')),
            'telefon'      => $this->input('telefon', $this->input('cep_telefon')),
            'dogum_tarihi' => DogumTarihi::coz(
                $this->input('dogum_tarihi'),
                $this->input('dogum_tarihi_gun'), $this->input('dogum_tarihi_ay'), $this->input('dogum_tarihi_yil'),
            ),
        ], fn ($v) => $v !== null));
    }

    public function rules(): array
    {
        $guncelleme = $this->route('id') !== null;

        return [
            'ad_soyad'     => ['required', 'string', 'max:255'],
            // Duzenlemede telefon zorunlu degil (maskeli gelirse korunur — servis).
            'telefon'      => [$guncelleme ? 'nullable' : 'required', 'string', 'max:30'],
            'email'        => ['nullable', 'email', 'max:255'],
            'tc_kimlik_no' => ['nullable', 'digits:11'],
            'dogum_tarihi' => ['nullable', 'date_format:Y-m-d'],
            'cinsiyet'     => ['nullable', Rule::in(array_keys(MusteriSozluk::CINSIYETLER))],
            'musteri_tipi' => ['nullable', Rule::in(array_keys(MusteriSozluk::REFERANSLAR))],
            'ozel_notlar'  => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Servise gecilecek duz dizi. */
    public function veri(): array
    {
        return $this->only(['ad_soyad', 'telefon', 'email', 'tc_kimlik_no', 'dogum_tarihi', 'cinsiyet', 'musteri_tipi', 'ozel_notlar'])
            + array_fill_keys(['telefon', 'email', 'tc_kimlik_no', 'dogum_tarihi', 'cinsiyet', 'musteri_tipi', 'ozel_notlar'], null);
    }
}
