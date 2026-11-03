<?php

namespace App\Http\Requests\Musteri;

use App\Support\DogrulamaMetinleri;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Musteri v2 yazma isteklerinin ortak tabani.
 *  - Yetki/kapsam controller'da (GuvenlikKapisi) — authorize() burada hep true.
 *  - Turkce mesajlar DogrulamaMetinleri'nden; musteri alan etiketleri burada eklenir.
 * Dogrulama hatasi bootstrap/app.php'de standart 422 zarfina doner.
 */
abstract class MusteriIstegi extends FormRequest
{
    private const ALANLAR = [
        'ad_soyad' => 'Ad soyad', 'tc_kimlik_no' => 'TC kimlik no', 'cinsiyet' => 'Cinsiyet',
        'musteri_tipi' => 'Referans', 'ozel_notlar' => 'Notlar', 'dogum_tarihi' => 'Doğum tarihi',
        'kara_liste' => 'Kara liste', 'cilt_tipi' => 'Cilt tipi', 'ek_saglik_sorunu' => 'Ek sağlık sorunu',
        'olcum_tarihi' => 'Ölçüm tarihi', 'olcum_saati' => 'Ölçüm saati', 'boy' => 'Boy', 'kilo' => 'Kilo',
        'yas' => 'Yaş', 'yag_orani' => 'Yağ oranı', 'odem' => 'Ödem', 'kas_puani' => 'Kas puanı',
        'kas_kg' => 'Kas (kg)', 'ic_yaglanma' => 'İç yağlanma', 'not' => 'Not',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return DogrulamaMetinleri::mesajlar() + ['digits' => ':attribute :digits haneli olmalı.'];
    }

    public function attributes(): array
    {
        return self::ALANLAR + DogrulamaMetinleri::alanlar();
    }
}
