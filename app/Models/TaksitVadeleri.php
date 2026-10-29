<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'taksit_vadeleri' — bir taksitli_tahsilat'in vadeleri. */
class TaksitVadeleri extends Model
{
    protected $table = 'taksit_vadeleri';

    protected $guarded = [];
}
