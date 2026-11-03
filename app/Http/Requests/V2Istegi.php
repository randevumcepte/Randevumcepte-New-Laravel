<?php

namespace App\Http\Requests;

use App\Support\DogrulamaMetinleri;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TUM v2 yazma isteklerinin (FormRequest) ortak tabani — Stok'ta dogdu, Kasa/Masraf da kullanir.
 *  - Yetki/kapsam controller'da (GuvenlikKapisi) — authorize() burada hep true.
 *  - $jsonAlanlar: eski istemciler (FormData / form-urlencoded) dizi alanlari JSON
 *    METIN olarak gonderiyordu; dogrulamadan once diziye cevrilir. v2 istemcileri
 *    zaten JSON govde gonderir.
 *  - $guncellemedeKismi: PATCH /{id} isteklerinde 'required' → 'sometimes'.
 * Dogrulama hatasi bootstrap/app.php'de standart 422 zarfina doner.
 */
abstract class V2Istegi extends FormRequest
{
    /** @var string[] */
    protected array $jsonAlanlar = [];

    protected bool $guncellemedeKismi = false;

    public function authorize(): bool
    {
        return true;
    }

    abstract protected function alanKurallari(): array;

    public function rules(): array
    {
        $kurallar = $this->alanKurallari();
        if (! $this->guncellemedeKismi || $this->route('id') === null) {
            return $kurallar;
        }

        return array_map(fn (array $k) => array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $k), $kurallar);
    }

    public function messages(): array
    {
        return DogrulamaMetinleri::mesajlar();
    }

    public function attributes(): array
    {
        return DogrulamaMetinleri::alanlar();
    }

    /**
     * Eski istemci (web / mobil v1) alan adlarini v2 adlarina cevirir — yalnizca v2 adi
     * gelmediyse. Gecis gunu istemci degismeden calissin diye (Ayar, On Gorusme ...).
     *
     * @param  array<string,string>  $esleme  eski ad => v2 adi
     */
    protected function eskiAdlar(array $esleme): void
    {
        $birlestir = [];
        foreach ($esleme as $eski => $yeni) {
            if (! $this->has($yeni) && $this->has($eski)) {
                $birlestir[$yeni] = $this->input($eski);
            }
        }
        $this->merge($birlestir);
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->jsonAlanlar as $alan) {
            $v = $this->input($alan);
            if (is_string($v) && $v !== '') {
                $this->merge([$alan => json_decode($v, true) ?? $v]);
            }
        }
    }
}
