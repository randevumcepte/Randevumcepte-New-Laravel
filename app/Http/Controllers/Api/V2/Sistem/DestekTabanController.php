<?php

namespace App\Http\Controllers\Api\V2\Sistem;

/** Destek ve icerik ekranlarinin alan etiketleri. */
abstract class DestekTabanController extends SistemTabanController
{
    protected const ETIKETLER = [
        'konu' => 'Konu', 'kategori' => 'Kategori', 'oncelik' => 'Öncelik', 'durum' => 'Durum', 'salon_id' => 'Salon',
        'iletisim_ad' => 'İletişim adı', 'iletisim_telefon' => 'İletişim telefonu', 'iletisim_email' => 'İletişim e-postası',
        'mesaj' => 'Mesaj', 'atanan_user_id' => 'Atanan', 'baslik' => 'Başlık', 'icerik' => 'İçerik', 'kisayol' => 'Kısayol',
        'hedef_tipi' => 'Hedef tipi', 'salon_ids' => 'Salonlar', 'il_ids' => 'İller', 'baslangic_tarihi' => 'Başlangıç',
        'bitis_tarihi' => 'Bitiş', 'cta_metin' => 'Eylem butonu metni', 'cta_link' => 'Eylem linki', 'aktif' => 'Aktif', 'sticky' => 'Sabit',
        'sms_adet' => 'SMS adedi', 'ucret' => 'Tutar', 'class' => 'Kart rengi', 'paket_adi' => 'Paket başlığı', 'alt_baslik' => 'Alt başlık',
        'hizmetler' => 'Hizmetler', 'hizmetler.*.ad' => 'Hizmet adı', 'hizmetler.*.adet' => 'Adet', 'notlar' => 'Not',
    ];
}
