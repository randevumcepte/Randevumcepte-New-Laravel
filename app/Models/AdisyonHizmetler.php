<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'adisyon_hizmetler' — adisyondaki hizmet kalemleri. */
class AdisyonHizmetler extends Model
{
    protected $table = 'adisyon_hizmetler';

    protected $guarded = [];

    /** Hizmet adi (hizmetler.hizmet_adi). */
    public function hizmet()
    {
        return $this->belongsTo(Hizmetler::class, 'hizmet_id');
    }

    /** Hizmeti veren personel (prim oranlari + personel_adi). */
    public function personel()
    {
        return $this->belongsTo(Personeller::class, 'personel_id');
    }

    /** Bu hizmet kalemine dusen tahsilat paylari (odenen hesabi). */
    public function tahsilatlar()
    {
        return $this->hasMany(TahsilatHizmetler::class, 'adisyon_hizmet_id');
    }
}
