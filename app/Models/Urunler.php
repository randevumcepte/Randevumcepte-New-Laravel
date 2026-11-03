<?php

namespace App\Models;

use App\Support\Metin;
use Illuminate\Database\Eloquent\Model;

/**
 * PAYLASILAN 'urunler' — urun katalogu. Satis satirinda urun_adi icin OKUNUR;
 * Stok v2 tanim/stok alanlarini YAZAR. stok_adedi = urun_depo_stoklari toplaminin
 * geriye-uyumlu kopyasi (yalniz StokHareketServisi gunceller).
 */
class Urunler extends Model
{
    protected $table = 'urunler';

    protected $guarded = [];

    protected $casts = [
        'aktif' => 'boolean',
    ];

    /** Eski modelle ayni: kelime basi buyuk harf (Turkce). */
    public function setUrunAdiAttribute($deger): void
    {
        $this->attributes['urun_adi'] = Metin::basHarfBuyut($deger);
    }

    public function kategori()
    {
        return $this->belongsTo(UrunKategorisi::class, 'kategori_id');
    }

    public function tedarikci()
    {
        return $this->belongsTo(Tedarikci::class, 'tedarikci_id');
    }

    public function depoStoklari()
    {
        return $this->hasMany(UrunDepoStoku::class, 'urun_id');
    }
}
