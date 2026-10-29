<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'randevu_hizmetler' tablosu (randevu kalemleri).
 *
 * randevular 1─∞ randevu_hizmetler. Her kalem bir hizmeti + ona atanan
 * kaynaklari (personel/oda/cihaz) ve zaman penceresini (saat/saat_bitis/sure_dk)
 * tutar. yardimci_personel=true satirlari yardimci personel atamasidir ve
 * takvim sayiminda ANA hizmet olarak sayilmaz. Sema eski sistemce yonetilir.
 */
class RandevuHizmetler extends Model
{
    protected $table = 'randevu_hizmetler';

    protected $guarded = [];

    /** Kapali-saat blogunu isaretleyen sentinel hizmet id ("kapali saat" sozde-hizmet). */
    public const KAPALI_SAAT_HIZMET_ID = 463;

    public function randevu()
    {
        return $this->belongsTo(Randevular::class, 'randevu_id');
    }
}
