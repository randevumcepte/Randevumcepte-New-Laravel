<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLAŞILAN 'arsiv' tablosu — müşteriye gönderilen form, hizmet
 * sözleşmesi ve harici (yüklenen) belgeler.
 *
 * durum: NULL bekliyor / 1 onaylandı / 0 iptal. form_id=0 → harici belge ya da
 * (is_sozlesme=1) sözleşme. Eski modelin $with eager-load'ları BİLİNÇLİ olarak yok:
 * okuma servisleri gereken alanları açıkça seçer (veri minimizasyonu).
 * Kolonlar eski sistemce yönetilir; migration YOK.
 */
class Arsiv extends Model
{
    protected $table = 'arsiv';

    protected $guarded = ['id'];
}
