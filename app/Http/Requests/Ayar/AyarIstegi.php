<?php

namespace App\Http\Requests\Ayar;

use App\Http\Requests\V2Istegi;
use App\Support\DogrulamaMetinleri;

/**
 * Ayar v2 yazma isteklerinin ortak tabani — Ayarlar ekranina ozgu alan etiketleri burada.
 * Yetki/kapsam controller'da; is kurali (saat sirasi vb.) servislerde.
 */
abstract class AyarIstegi extends V2Istegi
{
    private const ALANLAR = [
        'salon_adi' => 'İşletme adı', 'salon_turu_id' => 'İşletme türü', 'telefon_1' => 'Telefon',
        'whatsapp' => 'WhatsApp', 'aciklama' => 'Tanıtım yazısı', 'instagram_sayfa' => 'Instagram',
        'facebook_sayfa' => 'Facebook', 'vergi_adi' => 'Fatura unvanı', 'vergi_no' => 'Vergi / TC no',
        'vergi_dairesi' => 'Vergi dairesi', 'vergi_adresi' => 'Fatura adresi', 'meta_description' => 'SEO açıklaması',
        'anahtar_kelimeler' => 'Anahtar kelimeler', 'anahtar_kelimeler.*' => 'Anahtar kelime',
        'gunler' => 'Günler', 'gunler.*.haftanin_gunu' => 'Gün', 'gunler.*.baslangic_saati' => 'Başlangıç saati',
        'gunler.*.bitis_saati' => 'Bitiş saati', 'gunler.*.mola_baslangic_saati' => 'Mola başlangıcı',
        'gunler.*.mola_bitis_saati' => 'Mola bitişi', 'randevu_saat_araligi' => 'Randevu aralığı',
        'randevu_takvim_turu' => 'Takvim ayarı', 'takvim_tek_renk' => 'Takvim rengi', 'takvim_yazi_rengi' => 'Yazı rengi',
        'takvim_renk_sirasi' => 'Renk sırası', 'gunluk_limit' => 'Günlük online slot limiti', 'istisnalar' => 'İstisnalar',
        'istisnalar.*.tarih' => 'İstisna tarihi', 'istisnalar.*.tip' => 'İstisna tipi',
        'sadik.yuzde' => 'Sadık müşteri indirimi', 'aktif.yuzde' => 'Aktif müşteri indirimi',
        'cihaz_adi' => 'Cihaz adı', 'oda_adi' => 'Oda adı', 'personel_ids' => 'Personeller', 'hizmet_ids' => 'Hizmetler',
        'musait' => 'Durum', 'hizmet_adi' => 'Hizmet adı', 'sure_dk' => 'Süre', 'maliyet' => 'Maliyet', 'cinsiyet' => 'Cinsiyet',
        'cihaz_ids' => 'Cihazlar', 'kategori_adi' => 'Kategori adı', 'hizmetler' => 'Hizmetler', 'hizmetler.*.hizmet_id' => 'Hizmet',
        'hizmetler.*.fiyat' => 'Fiyat', 'hizmetler.*.sure_dk' => 'Süre', 'preset' => 'Tema', 'brightnessMode' => 'Görünüm modu', 'logo' => 'Logo', 'gorseller' => 'Görseller',
    ];

    public function messages(): array
    {
        return parent::messages() + [
            'required_if' => ':attribute zorunlu.',
            'present'     => ':attribute gönderilmeli.',
            'size.array'  => ':attribute :size kalem içermeli.',
            'min.string'  => ':attribute en az :min karakter olmalı.',
            'between.numeric' => ':attribute :min-:max arasında olmalı.',
        ];
    }

    public function attributes(): array
    {
        return self::ALANLAR + DogrulamaMetinleri::alanlar();
    }

    /** Eski istemci alan adlarini v2 adlarina cevirir (yalnizca v2 adi gelmediyse). */
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
}
