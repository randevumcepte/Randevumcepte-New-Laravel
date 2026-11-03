<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'tedarikciler' — salon tedarikcileri (silme = aktif=0). */
class Tedarikci extends Model
{
    protected $table = 'tedarikciler';

    protected $guarded = [];

    protected $casts = ['aktif' => 'boolean'];
}
