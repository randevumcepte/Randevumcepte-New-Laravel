<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Aktivite\HareketKategorisi;
use App\Domain\Aktivite\HareketKaynagi;
use App\Services\Aktivite\HareketSorgusu;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * LOG HAREKETLERİ v2 (eski /isletmeyonetim/log-hareketleri) — ince controller, SALT OKUMA.
 *
 *   GET /hareketler/secenekler?salon_id=
 *       → {kategoriler[], kaynaklar[], islemler[], kullanicilar[], hedef_tipleri[]}
 *   GET /hareketler?salon_id=&islem=&kategori=&kaynak=&kullanici_id=&hedef_tipi=&hedef_id=&tarih_bas=&tarih_bit=
 *       + ListeIstegi (page, per_page, ara, sirala=zaman|kullanici|islem|hedef, yon)
 *       → {data[], current_page, last_page, per_page, total}   (satır biçimi: HareketSunumu)
 *
 * Erişim eski ekranla BİREBİR: Hesap Sahibi + rol ataması olmayan yetkili görür; Yönetici (rol 4)
 * ve Personel (rol 5) göremez. Yazma ucu YOK — kayıtlar işlemlerin kendisinde AktiviteLog::yaz ile.
 */
class HareketController extends V2Controller
{
    /** Eski logHareketleri: model_has_roles role_id 4/5 → yetkisiz. */
    private const KAPALI_ROLLER = [4, 5];

    public function __construct(private HareketSorgusu $sorgu) {}

    public function secenekler(Request $request)
    {
        return ApiCevap::basarili($this->sorgu->secenekler($this->denetle($request)));
    }

    public function liste(Request $request)
    {
        $salonId = $this->denetle($request);
        $filtre = $request->validate([
            'islem'        => ['nullable', 'string', 'max:80'],
            'kategori'     => ['nullable', Rule::enum(HareketKategorisi::class)],
            'kaynak'       => ['nullable', Rule::enum(HareketKaynagi::class)],
            'kullanici_id' => ['nullable', 'integer', 'min:1'],
            'hedef_tipi'   => ['nullable', 'string', 'max:80'],
            'hedef_id'     => ['nullable', 'integer', 'min:1'],
            'tarih_bas'    => ['nullable', 'date_format:Y-m-d'],
            'tarih_bit'    => ['nullable', 'date_format:Y-m-d'],
        ]);
        $liste = ListeIstegi::istekten($request, 25, array_keys(HareketSorgusu::SIRALAMA), 'zaman', 'desc')->dizi();

        return ApiCevap::basarili($this->sorgu->listele($salonId, $liste, $filtre));
    }

    /** Kimlik + kapsam + rol kuralı; salon id döner. */
    private function denetle(Request $request): int
    {
        [$uid, $salonId] = $this->salonDenetle($request, []);
        app(GuvenlikKapisi::class)->rolDisiDenetle($uid, $salonId, self::KAPALI_ROLLER, 'Log hareketlerini yalnızca hesap sahibi görebilir');

        return $salonId;
    }
}
