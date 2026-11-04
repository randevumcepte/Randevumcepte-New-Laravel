<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** PAYLASILAN 'adisyon_hizmetler' — adisyondaki hizmet kalemleri. */
class AdisyonHizmetler extends Model
{
    protected $table = 'adisyon_hizmetler';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updated(function (self $kalem) {
            if ($kalem->wasChanged('fiyat')) {
                $kalem->fiyatiRandevuyaYansit();
            }
        });
    }

    /**
     * Randevuya bağlı kalemin yeni fiyatını randevu_hizmetler'e yazar. Paket seansı / seanslı
     * hizmet ve telafi kalemleri hariç. Aynı hizmet randevuda birden çok ise kalemler sırayla
     * eşleşir; yardımcı personel satırları ana kalemin fiyatını taşır.
     * Eski monolit App\AdisyonHizmetler ile aynı kural (web + mobil v1 oradan yazar).
     */
    public function fiyatiRandevuyaYansit(): void
    {
        if (! $this->randevu_id || (int) $this->seans_sayisi > 1) {
            return;
        }
        $paketSeansi = DB::table('adisyon_paket_seanslar')
            ->where('randevu_id', $this->randevu_id)->where('hizmet_id', $this->hizmet_id)->exists();
        if ($paketSeansi) {
            return;
        }

        $kalemIdleri = self::where('randevu_id', $this->randevu_id)->where('hizmet_id', $this->hizmet_id)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sira = array_search((int) $this->id, $kalemIdleri, true);

        $satirlar = DB::table('randevu_hizmetler')
            ->where('randevu_id', $this->randevu_id)->where('hizmet_id', $this->hizmet_id)
            ->whereNull('telafi_kaynak_rh_id')->orderBy('id')->get(['id', 'saat', 'yardimci_personel']);
        $ana = $satirlar->filter(fn ($s) => ! $s->yardimci_personel)->values()->get($sira);
        if (! $ana) {
            return;
        }

        $idler = $satirlar->filter(fn ($s) => $s->id == $ana->id || ($s->yardimci_personel && $s->saat == $ana->saat))
            ->pluck('id')->all();
        DB::table('randevu_hizmetler')->whereIn('id', $idler)
            ->update(['fiyat' => $this->fiyat, 'updated_at' => now()]);
    }

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
