<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\CakismaException;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\AktiviteLog;
use App\Support\ApiCevap;
use App\Support\RandevuYazma;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /randevu/{id}/sonrasi-not {not?} — islem sonrasi not (eski randevu_sonrasi_not_kaydet).
 * Yetki eskisiyle ayni: musteri.not_yaz, randevunun kendi salonunda.
 */
class RandevuNotController extends V2Controller
{
    public function __construct(private GuvenlikKapisi $kapi) {}

    public function kaydet(Request $request, int $id)
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu(DB::table('randevular')->where('id', $id)->value('salon_id'), 'Randevu bulunamadı');
        $this->kapi->denetle($uid, $salonId, 'musteri.not_yaz');
        $g = $this->alanlariDogrula($request, ['not' => ['nullable', 'string', 'max:65000']]);
        if (! RandevuYazma::acik($salonId)) {
            throw new CakismaException(RandevuYazma::KAPALI_MESAJ);
        }

        $not = $g['not'] ?? null;
        DB::table('randevular')->where('id', $id)->update(['randevu_sonrasi_not' => $not, 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $uid, 'randevu_sonrasi_not', 'randevu', $id, null, 'İşlem sonrası not kaydedildi.');

        return ApiCevap::basarili(['not' => (string) $not], 'Not kaydedildi.');
    }
}
