<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PAYLASILAN 'urun_depo_stoklari' — urun×depo anlik stok ONBELLEGI (stok_hareketleri
 * toplaminin ozeti). (urun_id, depo_id) UNIQUE. Yalniz StokHareketServisi yazar.
 */
class UrunDepoStoku extends Model
{
    protected $table = 'urun_depo_stoklari';

    protected $guarded = [];

    public function depo()
    {
        return $this->belongsTo(Depo::class, 'depo_id');
    }
}
