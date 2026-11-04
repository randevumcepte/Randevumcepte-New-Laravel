<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Sms\SmsKaraListeServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * SMS kara liste.
 *
 *   GET    /sms/kara-liste?salon_id= + ListeIstegi → {data:[{user_id, ad_soyad, telefon, eklenme_tarihi}], …meta}
 *   POST   /sms/kara-liste            {user_id}
 *   DELETE /sms/kara-liste/{userId}?salon_id=
 */
class SmsKaraListeController extends SmsTabanController
{
    public function __construct(private SmsKaraListeServisi $karaListe) {}

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $l = ListeIstegi::istekten($request, 10, SmsKaraListeServisi::SIRALANABILIR, 'tarih', 'desc');

        return ApiCevap::basarili($this->karaListe->liste($salonId, $this->kisit($uid, $salonId), $l, $this->telefonGor($uid, $salonId)));
    }

    public function ekle(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $v = $request->validate(['user_id' => ['required', 'integer', 'min:1']], ['user_id.required' => 'Müşteri seçiniz.']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->karaListe->degistir($uid, $salonId, $this->kisit($uid, $salonId), (int) $v['user_id'], true),
            'Müşteri SMS kara listesine eklendi; artık SMS bildirimi almayacak.', 201,
        );
    }

    public function sil(Request $request, int $userId)
    {
        [$uid, $salonId] = $this->salon($request);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->karaListe->degistir($uid, $salonId, $this->kisit($uid, $salonId), $userId, false),
            'Müşteri SMS kara listesinden çıkarıldı; artık SMS bildirimi alacak.',
        );
    }
}
