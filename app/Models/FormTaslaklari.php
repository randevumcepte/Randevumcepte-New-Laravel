<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLAŞILAN 'formtaslaklari' tablosu — salonun form şablonları.
 *
 * is_dinamik=1: şablon oluşturucuyla yapılmış (sorular_json); 0: eski sabit blade
 * şablonu (taslak/taslak2 view adı). is_sozlesme_tipi: hizmet sözleşmesi şablonu.
 * sira: listede gösterim sırası. Migration YOK.
 */
class FormTaslaklari extends Model
{
    protected $table = 'formtaslaklari';

    protected $guarded = ['id'];
}
