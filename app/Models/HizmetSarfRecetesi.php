<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'hizmet_sarf_receteleri' — hizmet basina otomatik dusulecek sarf urunleri (silme = aktif=0). */
class HizmetSarfRecetesi extends Model
{
    protected $table = 'hizmet_sarf_receteleri';

    protected $guarded = [];

    protected $casts = ['aktif' => 'boolean'];

    public function urun()
    {
        return $this->belongsTo(Urunler::class, 'urun_id');
    }
}
