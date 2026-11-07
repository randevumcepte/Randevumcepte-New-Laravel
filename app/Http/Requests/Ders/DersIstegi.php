<?php

namespace App\Http\Requests\Ders;

use App\Support\DogrulamaMetinleri;
use Illuminate\Foundation\Http\FormRequest;

/** Ders yazma isteklerinin tabani: yetki/kapsam controller'da; ortak alan kurallari + Turkce metinler. */
abstract class DersIstegi extends FormRequest
{
    private const ALANLAR = [
        'ders_tipi' => 'Ders adı', 'personel_id' => 'Eğitmen', 'hizmet_id' => 'Hizmet', 'tarih' => 'Tarih', 'saat' => 'Başlangıç saati',
        'saat_bitis' => 'Bitiş saati', 'kapasite' => 'Kapasite', 'hafta_gunu' => 'Gün', 'baslangic' => 'Başlangıç', 'bitis' => 'Bitiş',
        'hafta' => 'Hafta', 'musteri_id' => 'Müşteri', 'yeni_durum' => 'Durum', 'toplam_seans' => 'Seans sayısı',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return DogrulamaMetinleri::mesajlar();
    }

    public function attributes(): array
    {
        return self::ALANLAR + DogrulamaMetinleri::alanlar();
    }

    /** Oturum ve sablonda ortak ders alanlari. */
    protected function dersKurallari(): array
    {
        return [
            'ders_tipi'   => ['nullable', 'string', 'max:255'],
            'personel_id' => ['nullable', 'integer'],
            'hizmet_id'   => ['nullable', 'integer'],
            'saat'        => ['required', 'date_format:H:i'],
            // Mobil form bitis saatini denetlemez, bugunku kaydi korunur.
            'saat_bitis'  => $this->boolean('mobil') ? ['required', 'date_format:H:i'] : ['required', 'date_format:H:i', 'after:saat'],
            'kapasite'    => ['required', 'integer', 'min:1', 'max:1000'],
            'renk'        => ['nullable', 'string', 'max:20'],
        ];
    }
}
