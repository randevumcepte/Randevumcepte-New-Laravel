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
 *
 * Numara taramasi onlemi: cevap portfoy uyeligini acikladigi icin musteri.liste_gor, ekranin tek isi toplu ekleme oldugu
 * icin musteri.ekle_duzenle (IKISI DE); istek basina MAKS_NUMARA, kullanici + salon basina gunluk GUNLUK_NUMARA.
 */
class MusteriTelefonKontrolController extends V2Controller
{
    /** Rehberden secilen kisiler tek istekte gider; toplu ekleme zaten tek tek kayit atar (gercekci ust sinir). */
    private const MAKS_NUMARA = 500;

    /** Kullanici + salon basina 24 saatte sorulabilecek toplam numara. */
    private const GUNLUK_NUMARA = 2000;

    public function kontrol(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, 'musteri.liste_gor');
        $this->salonDenetle($request, 'musteri.ekle_duzenle');
        $g = $this->alanlariDogrula($request, ['phones' => ['required', 'array', 'max:'.self::MAKS_NUMARA], 'phones.*' => ['string', 'max:30']]);
        $this->kotaDenetle('telefon-kontrol', $uid, $salonId, [86400 => self::GUNLUK_NUMARA], count($g['phones']));

        return ApiCevap::basarili(['phones' => array_map(fn (string $tel) => [
            'phone'  => $tel,
            'exists' => DB::table('musteri_portfoy as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.salon_id', $salonId)->where('p.aktif', 1)
                ->whereIn('u.cep_telefon', TelefonNumarasi::aramaVaryantlari(TelefonNumarasi::kanonik($tel)) ?: [''])->exists(),
        ], $g['phones'])]);
    }
}
