<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Kampanya\SaatBosluguKampanyaSorgusu;
use App\Services\Kampanya\SaatBosluguKampanyaYazServisi;
use App\Services\Kampanya\SaatBosluguSmsServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Saat boşluğu kampanyaları.
 *
 *   GET  /kampanya/saat-boslugu?salon_id=                takvim şeridi
 *   GET  /kampanya/saat-boslugu/kontrol?salon_id=&saat=  tahsilat bandı
 *   POST /kampanya/saat-boslugu                          {salon_id, gapKey, gapLabel, startHour, endHour, discount}
 *   POST /kampanya/saat-boslugu/{id}/iptal               {salon_id}
 *   POST /kampanya/saat-boslugu/{id}/bildirim            {salon_id, maxAdet}
 *
 * Okumalar takvim ve tahsilatı gören her role açıktır, yalnız salon kapsamı denetlenir. Yazmalar
 * dashboard kartının gösterim kuralıyla aynıdır: rol 5 dışı + `randevu.takvim_gor`.
 */
class KampanyaSaatBosluguController extends V2Controller
{
    private const YETKI = 'randevu.takvim_gor';

    private const SMS_VARSAYILAN_ADET = 500;

    protected ?string $yazmaAlani = 'kampanya';

    public function aktifler(Request $request, SaatBosluguKampanyaSorgusu $sorgu)
    {
        [, $salonId] = $this->salonDenetle($request, []);

        return ApiCevap::basarili($sorgu->aktifler($salonId));
    }

    public function kontrol(Request $request, SaatBosluguKampanyaSorgusu $sorgu)
    {
        [, $salonId] = $this->salonDenetle($request, []);

        return ApiCevap::basarili($sorgu->kontrol($salonId, $request->filled('saat') ? (string) $request->input('saat') : null));
    }

    public function olustur(Request $request, SaatBosluguKampanyaYazServisi $yaz)
    {
        [$uid, $salonId] = $this->kartYetkisi($request);
        $this->yazmaAcikMi();

        return $this->sonuc($yaz->olustur($salonId, [
            'anahtar' => (string) $request->input('gapKey', 'morning'),
            'ad'      => mb_substr((string) $request->input('gapLabel', 'Saatler'), 0, 100),
            'bas'     => (int) $request->input('startHour', 9),
            'bit'     => (int) $request->input('endHour', 12),
            'indirim' => (int) $request->input('discount', 25),
        ], $uid));
    }

    public function iptal(Request $request, SaatBosluguKampanyaYazServisi $yaz, int $id)
    {
        [$uid, $salonId] = $this->kartYetkisi($request);
        $this->yazmaAcikMi();
        $yaz->iptal($salonId, $id, $uid);

        return ApiCevap::basarili(['status' => 'success'], 'Kampanya iptal edildi.');
    }

    public function bildirim(Request $request, SaatBosluguSmsServisi $sms, int $id)
    {
        [$uid, $salonId] = $this->kartYetkisi($request);
        $this->yazmaAcikMi();

        return $this->sonuc($sms->gonder($salonId, $id, max(1, (int) $request->input('maxAdet', self::SMS_VARSAYILAN_ADET)), $uid));
    }

    /** @return array{0:int,1:int} */
    private function kartYetkisi(Request $request): array
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        app(GuvenlikKapisi::class)->yoneticiDenetle($uid, $salonId);

        return [$uid, $salonId];
    }

    private function sonuc(array $s)
    {
        $mesaj = $s['mesaj'];
        unset($s['mesaj']);

        return ApiCevap::basarili($s, $mesaj);
    }
}
