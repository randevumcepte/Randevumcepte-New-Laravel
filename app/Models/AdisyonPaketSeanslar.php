<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'adisyon_paket_seanslar' (APS) tablosu.
 *
 * Bir randevunun hangi satista (adisyon_hizmetler / adisyon_paketler)
 * seans dusumu yaptigini tutar. Takvimde "paket/karma" etiketi ve telafi
 * rozeti bu kayitlardan hesaplanir.
 *
 * geldi:  null = beklemede, 1/true = geldi, 0/false = gelmedi,
 *         2 = TELAFI slotu (ucretsiz duzeltme; seans dusmez, korunur).
 *
 * Sema eski sistemce yonetilir (burada migration YOK).
 */
class AdisyonPaketSeanslar extends Model
{
    protected $table = 'adisyon_paket_seanslar';

    protected $guarded = [];
}
