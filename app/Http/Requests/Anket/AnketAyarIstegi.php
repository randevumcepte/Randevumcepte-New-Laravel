<?php

namespace App\Http\Requests\Anket;

use App\Domain\Anket\AnketOdulu;
use App\Http\Requests\V2Istegi;

/** PATCH /anket/ayarlar — kısmi (gelmeyen alan korunur). Eski web alanı google_input da kabul. */
class AnketAyarIstegi extends V2Istegi
{
    use OdulKurallari;

    protected function alanKurallari(): array
    {
        return [
            'google_url'                => ['nullable', 'string', 'max:2000'],
            'google_review_esik_nps'    => ['sometimes', 'integer', 'min:0', 'max:10'],
            'google_review_esik_csat'   => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'kotu_puan_uyari_telefon'   => ['nullable', 'string', 'max:25'],
            'kotu_puan_uyari_esik_nps'  => ['sometimes', 'integer', 'min:0', 'max:10'],
            'kotu_puan_uyari_esik_csat' => ['sometimes', 'numeric', 'min:1', 'max:5'],
        ] + $this->odulKurallari(AnketOdulu::ONEK_GOOGLE);
    }

    public function attributes(): array
    {
        return [
            'google_url' => 'Yorum bağlantısı', 'kotu_puan_uyari_telefon' => 'Uyarı telefonu',
            'google_review_esik_nps' => 'Google NPS eşiği', 'google_review_esik_csat' => 'Google yıldız eşiği',
            'kotu_puan_uyari_esik_nps' => 'Uyarı NPS eşiği', 'kotu_puan_uyari_esik_csat' => 'Uyarı yıldız eşiği',
        ] + $this->odulAlanlari(AnketOdulu::ONEK_GOOGLE) + parent::attributes();
    }

    protected function prepareForValidation(): void
    {
        $this->eskiAdlar(['google_input' => 'google_url']);
        parent::prepareForValidation();
    }
}
