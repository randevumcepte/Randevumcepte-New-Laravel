<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'seans_cihaz_verileri' tablosu — lazer seansinda bolge
 * basina cihaz parametreleri (enerji/hiz/ms/atis_sayisi serbest metin).
 * seans_id → adisyon_paket_seanslar.id. Sema eski sistemce yonetilir (migration YOK).
 */
class SeansCihazVerileri extends Model
{
    protected $table = 'seans_cihaz_verileri';

    protected $guarded = [];
}
