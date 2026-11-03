<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PAYLASILAN 'stok_hareketleri' — stogun DEFTERI (append-only). Her stok degisimi
 * bir satirdir; miktar isaretlidir (+giris / -cikis). Yalniz StokHareketServisi yazar.
 */
class StokHareketi extends Model
{
    protected $table = 'stok_hareketleri';

    protected $guarded = [];

    protected $casts = ['tarih' => 'datetime'];
}
