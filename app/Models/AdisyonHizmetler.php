<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'adisyon_hizmetler' — adisyondaki hizmet kalemleri. */
class AdisyonHizmetler extends Model
{
    protected $table = 'adisyon_hizmetler';

    protected $guarded = [];

    /**
     * PAKET satışı sayılan seanslı hizmet mi — TEK kural (eski panel + mobil ile aynı):
     * randevudan gelmemiş (randevu_id NULL) VE seans_sayisi > 0.
     */
    public function seansliMi(): bool
    {
        return is_null($this->randevu_id) && (int) ($this->seans_sayisi ?? 0) > 0;
    }

    /** Girilen seans → kolon değeri: boş veya ≤1 tekil hizmet (NULL), 2+ paket satışı (eski kural). */
    public static function seansNormal($girilen): ?int
    {
        return (int) $girilen > 1 ? (int) $girilen : null;
    }

    /** Hizmet adi (hizmetler.hizmet_adi). */
    public function hizmet()
    {
        return $this->belongsTo(Hizmetler::class, 'hizmet_id');
    }

    /** Hizmeti veren personel (prim oranlari + personel_adi). */
    public function personel()
    {
        return $this->belongsTo(Personeller::class, 'personel_id');
    }

    /** Bu hizmet kalemine dusen tahsilat paylari (odenen hesabi). */
    public function tahsilatlar()
    {
        return $this->hasMany(TahsilatHizmetler::class, 'adisyon_hizmet_id');
    }
}
