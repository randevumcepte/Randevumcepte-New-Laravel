<?php

namespace App\Models;

use App\Support\Metin;
use Illuminate\Database\Eloquent\Model;

/** PAYLASILAN 'paketler' — paket katalogu (Paket Yonetimi + satis satirinda paket_adi). */
class Paketler extends Model
{
    protected $table = 'paketler';

    protected $guarded = [];

    /** Eski App\Paketler ile ayni: ad her kelimenin bas harfi buyuk saklanir. */
    public function setPaketAdiAttribute(?string $deger): void
    {
        $this->attributes['paket_adi'] = Metin::basHarfBuyut($deger === null ? null : trim($deger));
    }

    public function hizmetler()
    {
        return $this->hasMany(PaketHizmetler::class, 'paket_id');
    }
}
