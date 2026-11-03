<?php

namespace App\Http\Requests\Ayar;

use App\Domain\Ayar\AyarSozluk;
use Illuminate\Validation\Rule;

/**
 * POST /ayar/hizmetler (salona ozel yeni hizmet) · PATCH /ayar/hizmetler/{id} (kismi).
 * Eski karisik liste de kabul edilir: personel_ids = [12, "cihaz-5"] → personel_ids [12] + cihaz_ids [5];
 * eski adlar: hizmet_kategorisi → kategori_id, hizmet_sure → sure_dk, hizmet_fiyati → fiyat,
 * hizmet_maliyet → maliyet, personeller → personel_ids.
 */
class HizmetIstegi extends AyarIstegi
{
    protected array $jsonAlanlar = ['personel_ids', 'cihaz_ids'];

    protected function prepareForValidation(): void
    {
        $this->eskiAdlar(['hizmet_kategorisi' => 'kategori_id', 'hizmet_sure' => 'sure_dk', 'hizmet_fiyati' => 'fiyat',
            'hizmet_maliyet' => 'maliyet', 'personeller' => 'personel_ids']);
        parent::prepareForValidation();

        $karisik = $this->input('personel_ids');
        if (is_array($karisik) && array_filter($karisik, fn ($v) => is_string($v) && str_starts_with($v, 'cihaz-'))) {
            $this->merge([
                'personel_ids' => array_values(array_filter($karisik, fn ($v) => ! (is_string($v) && str_starts_with($v, 'cihaz-')))),
                'cihaz_ids'    => array_values(array_merge((array) $this->input('cihaz_ids', []), array_map(
                    fn ($v) => (int) substr($v, 6), array_filter($karisik, fn ($v) => is_string($v) && str_starts_with($v, 'cihaz-'))))),
            ]);
        }
    }

    protected function alanKurallari(): array
    {
        $yeni = $this->route('id') === null;
        $zorunlu = $yeni ? 'required' : 'sometimes';

        return [
            'hizmet_adi'     => [$zorunlu, 'string', 'max:255'],
            'kategori_id'    => [$zorunlu, 'integer', 'exists:hizmet_kategorisi,id'],
            'sure_dk'        => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
            'fiyat'          => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'maliyet'        => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'cinsiyet'       => ['sometimes', 'nullable', 'integer', Rule::in(array_column(AyarSozluk::CINSIYETLER, 'kod'))],
            'personel_ids'   => ['sometimes', 'array', 'max:200'],
            'personel_ids.*' => ['integer', 'min:1'],
            'cihaz_ids'      => ['sometimes', 'array', 'max:200'],
            'cihaz_ids.*'    => ['integer', 'min:1'],
        ];
    }

    public function veri(): array
    {
        return $this->validated();
    }
}
