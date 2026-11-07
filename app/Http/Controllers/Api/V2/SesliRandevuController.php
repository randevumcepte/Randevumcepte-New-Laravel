<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Randevu\SesliRandevuCozServisi;
use App\Services\Randevu\SesliRandevuMusaitlikServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sesli randevu musaitlik sorgulari: salt okunur, salonun her uyesi cagirir. Personel salona ait degilse
 * bilgi donmez. Eski uclarin (sesli-randevu-musaitlik, sesli-randevu-takvim-durumu) kimliksiz acigi kapanir.
 *   GET /randevu/sesli/coz?salon_id=&metin=&personel_id=&tum_personel=   komut cozumu; randevu olusturmaz
 *   GET /randevu/sesli/musaitlik?salon_id=&personel_id=&hizmet_id=&tarih=&saat=&vakit=
 *   GET /randevu/sesli/takvim-durumu?personel_id=
 */
class SesliRandevuController extends V2Controller
{
    public function __construct(private SesliRandevuMusaitlikServisi $musaitlik, private GuvenlikKapisi $kapi) {}

    /** Baska salonun personeli istenemez; `tum_personel` yalniz salonun personel adlarini cozer. */
    public function coz(Request $request, SesliRandevuCozServisi $coz)
    {
        [, $salonId] = $this->salonDenetle($request, []);
        $metin = trim((string) $request->input('metin', ''));
        if ($metin === '') {
            return ApiCevap::basarili(['basarili' => false, 'hata' => 'Bos metin. Once konusun ya da yazin.']);
        }
        $personelId = $request->integer('personel_id') ?: null;
        if ($personelId && ! DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->exists()) {
            $personelId = null;
        }

        return ApiCevap::basarili($coz->coz($metin, $salonId, $personelId, $request->boolean('tum_personel')) + ['salon_id' => $salonId]);
    }

    public function musaitlik(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, []);
        $personelId = $request->integer('personel_id') ?: null;
        if ($personelId && ! DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->exists()) {
            $personelId = null;
        }

        return ApiCevap::basarili($this->musaitlik->bul(
            $salonId,
            $personelId,
            $request->integer('hizmet_id'),
            $request->filled('tarih') ? (string) $request->input('tarih') : null,
            $request->filled('saat') ? (string) $request->input('saat') : null,
            $request->filled('vakit') ? (string) $request->input('vakit') : null,
        ));
    }

    public function takvimDurumu(Request $request)
    {
        $uid = (int) $this->kullanici()->id;
        $personelId = $request->integer('personel_id');
        $salonId = (int) DB::table('salon_personelleri')->where('id', $personelId)->value('salon_id');
        if (! $salonId) {
            return ApiCevap::basarili(['acik' => false, 'hizmet_var' => false]);
        }
        $this->kapi->kapsamDenetle($uid, $salonId);

        return ApiCevap::basarili($this->musaitlik->takvimDurumu($personelId));
    }
}
