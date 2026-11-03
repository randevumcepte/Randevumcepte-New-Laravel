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

    /** Salonun detayli prim oranlari: [personel_id][tur][kalem_id] => yuzde (eski detayliPrimHaritasi). */
    public static function harita(int $salonId): array
    {
        $harita = [];
        foreach (self::where('salon_id', $salonId)->get(['personel_id', 'tur', 'kalem_id', 'yuzde']) as $s) {
            $harita[$s->personel_id][$s->tur][$s->kalem_id] = (float) $s->yuzde;
        }

        return $harita;
    }
}
