<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'ders_tekrarli_katilim': musterinin seanslarini secilen
 * slotlara dagitan otomatik ders plani (stüdyo modu). gunler/saatler/sablonlar eski
 * sistem de okudugu icin JSON METIN olarak saklanir; 'array' cast ayni bicimi yazar.
 */
class DersTekrarliKatilim extends Model
{
    protected $table = 'ders_tekrarli_katilim';

    protected $fillable = [
        'salon_id', 'user_id', 'hizmet_id', 'adisyon_paket_id', 'adisyon_hizmet_id', 'toplam_seans',
        'gunler', 'saatler', 'sablonlar', 'personel_id', 'baslangic_tarihi', 'aktif',
    ];

    protected $casts = ['gunler' => 'array', 'saatler' => 'array', 'sablonlar' => 'array'];
}
