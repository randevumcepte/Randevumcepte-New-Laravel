<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Planlanan alacak (receivable) — eski monolitle PAYLAŞILAN 'alacaklar' tablosu.
 *
 * Satış & Tahsilat ekranında "Alacak Oluştur": müşterinin bu adisyondan kalan
 * borcunu, vade (planlanan_odeme_tarihi) + not ile kayda geçirir. Eski sistemde
 * alacakekleduzenle adisyon_id'yi SET ETMEZDİ → kayıt alacakgetir (adisyon_id
 * INNER JOIN) listesinde GÖRÜNMEZDİ (yetim). v2 adisyon_id'yi bağlar.
 *
 * timestamps açık: tablo created_at + updated_at tutar (eski model de öyle save eder).
 */
class Alacaklar extends Model
{
    protected $table = 'alacaklar';

    protected $guarded = [];
}
