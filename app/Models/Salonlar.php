<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'salonlar' tablosu (isletme/sube).
 *
 * Satis listesinde yalnizca OKUNUR: salon_adi (musteri panelinde bundle'a ait
 * subeyi etiketlemek icin), studyo_modu (acik/kapali'yi 'odendi' bayragiyla
 * belirlemek icin — fiyat 0 tutulur, kalan hep 0), faturasiz_gizle (fatura
 * kesilmemis adisyonlari listeden gizleme ayari), app_bundle (white-label
 * musteri panelinde markanin tum subelerini kapsamak icin). Migration YOK.
 */
class Salonlar extends Model
{
    protected $table = 'salonlar';

    protected $guarded = [];
}
