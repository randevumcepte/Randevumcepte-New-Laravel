<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'personel_mola_saatleri' tablosu.
 * Haftanin 7 gunu icin personelin mola (ogle arasi) penceresi.
 */
class PersonelMolaSaatleri extends Model
{
    protected $table = 'personel_mola_saatleri';

    protected $guarded = [];
}
