<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'adisyon_urunler' — adisyondaki urun kalemleri (adet'li). */
class AdisyonUrunler extends Model
{
    protected $table = 'adisyon_urunler';

    protected $guarded = [];

    /** Urun adi (urunler.urun_adi). */
    public function urun()
    {
        return $this->belongsTo(Urunler::class, 'urun_id');
    }

    /** Urunu satan personel (prim oranlari + personel_adi). */
    public function personel()
    {
        return $this->belongsTo(Personeller::class, 'personel_id');
    }

    /** Bu urun kalemine dusen tahsilat paylari (odenen hesabi). */
    public function tahsilatlar()
    {
        return $this->hasMany(TahsilatUrunler::class, 'adisyon_urun_id');
    }
}
