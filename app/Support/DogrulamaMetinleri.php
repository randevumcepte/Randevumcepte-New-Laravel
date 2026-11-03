<?php

namespace App\Support;

/**
 * Turkce dogrulama mesajlari — uygulamada lang/tr yok; v2 istemcileri (web toast,
 * Flutter snackbar) 422 zarfindaki ilk alan mesajini KULLANICIYA gosterir.
 * Kullanim: Validator::make($g, $kurallar, DogrulamaMetinleri::mesajlar(), DogrulamaMetinleri::alanlar())
 */
final class DogrulamaMetinleri
{
    /** Alan adi → okunur etiket (:attribute). */
    private const ALANLAR = [
        'urun_adi' => 'Ürün adı', 'fiyat' => 'Satış fiyatı', 'alis_fiyati' => 'Alış fiyatı', 'kdv_orani' => 'KDV',
        'birim' => 'Birim', 'tip' => 'Tip', 'barkod' => 'Barkod', 'sku' => 'SKU', 'stok_adedi' => 'Stok',
        'dusuk_stok_siniri' => 'Düşük stok sınırı', 'kritik_stok_siniri' => 'Kritik stok sınırı',
        'aciklama' => 'Açıklama', 'urun_id' => 'Ürün', 'depo_id' => 'Depo', 'miktar' => 'Miktar',
        'hareket_tipi' => 'Hareket tipi', 'kalemler' => 'Kalemler', 'kalemler.*.urun_id' => 'Ürün',
        'kalemler.*.miktar' => 'Miktar', 'kalemler.*.sayilan_miktar' => 'Sayılan miktar',
        'kalemler.*.birim_alis_fiyati' => 'Birim alış fiyatı', 'kaynak_depo_id' => 'Kaynak depo',
        'hedef_depo_id' => 'Hedef depo', 'sepet' => 'Sepet', 'sepet.*.urun_id' => 'Ürün', 'sepet.*.miktar' => 'Miktar',
        'musteri_id' => 'Müşteri', 'personel_id' => 'Personel', 'odeme_yontemi_id' => 'Ödeme yöntemi',
        'satis_tarihi' => 'Satış tarihi', 'ad' => 'Ad', 'renk' => 'Renk', 'sira' => 'Sıra', 'depo_adi' => 'Depo adı',
        'telefon' => 'Telefon', 'vergi_no' => 'Vergi no', 'email' => 'E-posta', 'adres' => 'Adres',
        'hizmet_id' => 'Hizmet', 'tedarikci_id' => 'Tedarikçi', 'kategori_id' => 'Kategori',
    ];

    public static function mesajlar(): array
    {
        return [
            'required'             => ':attribute zorunlu.',
            'required_if_accepted' => ':attribute zorunlu.',
            'numeric'              => ':attribute sayı olmalı.',
            'integer'              => ':attribute tam sayı olmalı.',
            'string'               => ':attribute metin olmalı.',
            'boolean'              => ':attribute evet/hayır olmalı.',
            'array'                => ':attribute liste olmalı.',
            'email'                => ':attribute geçerli bir e-posta olmalı.',
            'min.numeric'          => ':attribute en az :min olmalı.',
            'min.array'            => ':attribute en az :min kalem içermeli.',
            'max.numeric'          => ':attribute en fazla :max olabilir.',
            'max.string'           => ':attribute en fazla :max karakter olabilir.',
            'max.array'            => ':attribute en fazla :max kalem içerebilir.',
            'gt.numeric'           => ':attribute sıfırdan büyük olmalı.',
            'not_in'               => ':attribute geçersiz.',
            'in'                   => ':attribute geçersiz.',
            'enum'                 => ':attribute geçersiz.',
            'regex'                => ':attribute biçimi geçersiz.',
            'exists'               => ':attribute bulunamadı.',
            'different'            => ':attribute ile :other aynı olamaz.',
            'date_format'          => ':attribute :format biçiminde olmalı.',
            'distinct'             => ':attribute birden fazla kez seçilmiş.',
        ];
    }

    public static function alanlar(): array
    {
        return self::ALANLAR;
    }
}
