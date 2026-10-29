<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'personel_calisma_saatleri' tablosu.
 * Haftanin 7 gunu icin personelin calisma penceresi.
 */
class PersonelCalismaSaatleri extends Model
{
    protected $table = 'personel_calisma_saatleri';

    protected $guarded = [];
}
