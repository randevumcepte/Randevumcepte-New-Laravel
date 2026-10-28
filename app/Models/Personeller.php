<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'salon_personelleri' tablosu.
 *
 * Yetki sisteminde yetkili (IsletmeYetkilileri) ile personel kaydi arasindaki
 * bag burada: `yetkili_id` + `salon_id`. `role_id` rolu belirtir
 * (model_has_roles ile birlikte kullanilir). Sema eski sistemce yonetilir.
 */
class Personeller extends Model
{
    protected $table = 'salon_personelleri';

    protected $guarded = [];
}
