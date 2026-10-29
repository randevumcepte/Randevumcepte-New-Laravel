<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PAYLASILAN 'personel_prim_oranlari' — kalem bazli prim orani (detayli prim).
 *
 * {tur}_prim_detayli=1 olan personel icin tur+kalem_id basina ozel yuzde tutar.
 * Satis hakediş hesabinda OKUNUR: [personel_id][tur][kalem_id] => yuzde haritasi.
 * tur degerleri: 'hizmet' | 'urun' | 'paket'. Migration YOK.
 */
class PersonelPrimOrani extends Model
{
    protected $table = 'personel_prim_oranlari';

    protected $guarded = [];
}
