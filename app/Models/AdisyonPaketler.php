<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'adisyon_paketler' — adisyondaki paket kalemleri. */
class AdisyonPaketler extends Model
{
    protected $table = 'adisyon_paketler';

    protected $guarded = [];

    /** Paket adi (paketler.paket_adi). */
    public function paket()
    {
        return $this->belongsTo(Paketler::class, 'paket_id');
    }

    /** Paketi satan personel (prim oranlari + personel_adi). */
    public function personel()
    {
        return $this->belongsTo(Personeller::class, 'personel_id');
    }

    /** Bu paket kalemine dusen tahsilat paylari (odenen hesabi). */
    public function tahsilatlar()
    {
        return $this->hasMany(TahsilatPaketler::class, 'adisyon_paket_id');
    }
}
