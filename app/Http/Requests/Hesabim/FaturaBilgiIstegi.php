<?php

namespace App\Http\Requests\Hesabim;

use App\Http\Requests\V2Istegi;

/**
 * PUT /api/v2/hesabim/fatura-bilgisi — sınırlar Ayarlar → İşletme Bilgileri ile aynı alanlar.
 * mobil=1: mobil ekran metin sınırlarıyla hiç karşılaşmadı; yalnız kolon sınırı aranır. KDV oranı her yolda 0-100.
 */
class FaturaBilgiIstegi extends V2Istegi
{
    protected function alanKurallari(): array
    {
        $mobil = $this->boolean('mobil');

        return [
            'vergi_adi'    => ['nullable', 'string', 'max:255'],
            'vergi_no'     => ['nullable', 'string', 'max:'.($mobil ? 255 : 20)],
            'vergi_adresi' => ['nullable', 'string', 'max:'.($mobil ? 65535 : 1000)],
            'kdv_orani'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'vergi_adi' => 'Fatura unvanı', 'vergi_no' => 'Vergi / TC no', 'vergi_adresi' => 'Fatura adresi',
        ] + parent::attributes();
    }
}
