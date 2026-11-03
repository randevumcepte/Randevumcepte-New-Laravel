<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'depolar' — salon depolari; salon basina tek 'varsayilan' (silme = aktif=0). */
class Depo extends Model
{
    protected $table = 'depolar';

    protected $guarded = [];

    protected $casts = ['varsayilan' => 'boolean', 'aktif' => 'boolean'];
}
