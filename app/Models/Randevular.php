<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'randevular' tablosu (randevu basligi).
 *
 * Sema eski sistemce yonetilir (burada migration YOK). Durum sabitleri eski
 * App\Randevular ile BIREBIR:
 *   0 = Bekleniyor (online talep)   1 = Onaylandi
 *   2 = Iptal edildi                3 = Kabul edilmedi
 *
 * Takvim yalnizca durum < 2 kayitlari gosterir. "Kapali saat" bloklari ayni
 * tabloda user_id=2012 sentineli ile tutulur.
 */
class Randevular extends Model
{
    protected $table = 'randevular';

    protected $guarded = [];

    public const BEKLENIYOR     = 0;
    public const ONAYLANDI      = 1;
    public const IPTAL_EDILDI   = 2;
    public const KABUL_EDILMEDI = 3;

    /** Kapali-saat bloklarini isaretleyen sentinel musteri id. */
    public const KAPALI_SAAT_USER_ID = 2012;
}
