<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eski monolitle PAYLASILAN 'ders_programi_sablonu': haftalik sabit ders (hafta_gunu 1=Pzt..7=Paz). */
class DersProgramiSablonu extends Model
{
    protected $table = 'ders_programi_sablonu';

    protected $fillable = [
        'salon_id', 'sube_id', 'personel_id', 'hafta_gunu', 'saat', 'saat_bitis',
        'ders_tipi', 'hizmet_id', 'kapasite', 'renk', 'aktif',
    ];

    public function personel(): BelongsTo
    {
        return $this->belongsTo(Personeller::class, 'personel_id');
    }
}
