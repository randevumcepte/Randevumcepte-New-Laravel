<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'urun_kategoriler' — salona ozel urun kategorileri (silme = aktif=0). */
class UrunKategorisi extends Model
{
    protected $table = 'urun_kategoriler';

    protected $guarded = [];

    protected $casts = ['aktif' => 'boolean'];
}
