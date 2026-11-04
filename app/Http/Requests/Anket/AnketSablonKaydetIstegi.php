<?php

namespace App\Http\Requests\Anket;

use App\Domain\Anket\AnketOdulu;
use App\Http\Requests\V2Istegi;

/**
 * POST /anket/sablonlar · PATCH /anket/sablonlar/{id}. PATCH kısmidir (mobil v1 gibi yalnız gelen alan).
 * Sorular `sorular` dizisi ya da mobil v1'in `sorular_json` metni olabilir; içerik kuralları
 * Domain\Anket\AnketSorulari'nde (servis 422 döner).
 */
class AnketSablonKaydetIstegi extends V2Istegi
{
    use OdulKurallari;

    protected array $jsonAlanlar = ['sorular'];

    protected bool $guncellemedeKismi = true;

    protected function alanKurallari(): array
    {
        return [
            'ad'                => ['required', 'string', 'max:200'],
            'aciklama'          => ['nullable', 'string', 'max:2000'],
            'sorular'           => ['required', 'array'],
            'otomatik_gonder'   => ['boolean'],
            'gonder_saat_sonra' => ['nullable', 'integer', 'min:0', 'max:720'],
            'varsayilan'        => ['boolean'],
            'aktif'             => ['boolean'],
        ] + $this->odulKurallari(AnketOdulu::ONEK_SABLON);
    }

    public function attributes(): array
    {
        return ['ad' => 'Anket adı', 'aciklama' => 'Açıklama', 'sorular' => 'Sorular', 'gonder_saat_sonra' => 'Gecikme (saat)']
            + $this->odulAlanlari(AnketOdulu::ONEK_SABLON) + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        $this->eskiAdlar(['sorular_json' => 'sorular']);
        parent::prepareForValidation();
    }
}
