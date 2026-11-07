<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Telefon\TelefonNumarasi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /musteri/telefon-kontrol {salon_id, phones[]} → {phones:[{phone, exists}]} — rehberden toplu ekleme (v1 check_phone).
 * v1 kimliksizdi ve eslesen kisilerin adini donuyordu; burada isletme token'i ve salon kapsami zorunlu, cevapta yalniz var/yok.
 * exists: numara bu salonun aktif portfoyunde (platformun numara rehberi degil). Ekran exists olmayani normal musteri
 * kaydina yollar; baska salonun musterisi ya da pasif kayit o yoldan portfoye girer.
 * Numara istemcinin gonderdigi bicimle doner: ekran kendi bicimiyle eslestirir.
 */
class MusteriTelefonKontrolController extends V2Controller
{
    public function kontrol(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, []);
        $g = $request->validate(['phones' => ['required', 'array', 'max:2000'], 'phones.*' => ['string', 'max:30']]);

        return ApiCevap::basarili(['phones' => array_map(fn (string $tel) => [
            'phone'  => $tel,
            'exists' => DB::table('musteri_portfoy as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.salon_id', $salonId)->where('p.aktif', 1)
                ->whereIn('u.cep_telefon', TelefonNumarasi::aramaVaryantlari(TelefonNumarasi::kanonik($tel)) ?: [''])->exists(),
        ], $g['phones'])]);
    }
}
