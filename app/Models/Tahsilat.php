<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'tahsilatlar' — kasaya giren odemeler (ledger header). */
class Tahsilat extends Model
{
    protected $table = 'tahsilatlar';

    protected $guarded = [];
}
