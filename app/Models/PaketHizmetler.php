<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'paket_hizmetler' — paketin hizmetleri (hizmet basina opsiyonel seans / fiyat). */
class PaketHizmetler extends Model
{
    protected $table = 'paket_hizmetler';

    protected $fillable = ['paket_id', 'hizmet_id', 'seans', 'fiyat'];

    public function hizmet()
    {
        return $this->belongsTo(Hizmetler::class, 'hizmet_id');
    }
}
