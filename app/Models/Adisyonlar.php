<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'adisyonlar' tablosu (satis basligi). Yeni app Faz 1'de yalnizca OKUR. */
class Adisyonlar extends Model
{
    protected $table = 'adisyonlar';

    protected $guarded = [];

    public function hizmetler()
    {
        return $this->hasMany(AdisyonHizmetler::class, 'adisyon_id');
    }

    public function urunler()
    {
        return $this->hasMany(AdisyonUrunler::class, 'adisyon_id');
    }

    public function paketler()
    {
        return $this->hasMany(AdisyonPaketler::class, 'adisyon_id');
    }

    public function tahsilatlar()
    {
        return $this->hasMany(Tahsilat::class, 'adisyon_id');
    }

    /** Adisyon sahibi musteri (users.name). */
    public function musteri()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Adisyonun ait oldugu salon/sube (salon_adi + studyo_modu etiketi icin). */
    public function salon()
    {
        return $this->belongsTo(Salonlar::class, 'salon_id');
    }
}
