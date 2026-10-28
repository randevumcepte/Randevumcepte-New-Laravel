<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'personel_yetki_ayarlari' tablosu — yetki store'u.
 *
 * Her (personel_id, salon_id) icin: secili `sablon` + `ayarlar` (anahtar=>bool JSON).
 * `ayarlar` JSON (LONGTEXT) olarak tutulur; 'array' cast ile diziye cozulur.
 * Sema eski sistemce yonetilir; yeni app yalnizca OKUR (Faz 1).
 */
class PersonelYetkiAyari extends Model
{
    protected $table = 'personel_yetki_ayarlari';

    protected $guarded = [];

    protected $casts = [
        'ayarlar' => 'array',
    ];
}
