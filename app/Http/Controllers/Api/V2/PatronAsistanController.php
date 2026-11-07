<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\PatronAsistan\PatronAsistanAkisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Patron asistani (eski /patron-asistan-sor, /patron-asistan-uygula).
 * Kimlik + kapsam + rapor.ciro_kar_gor: eskisi gibi yalniz Hesap Sahibi ve Yonetici. Gonderim onay sonrasi
 * uygula ucunda, BILDIRIM_REKLAM_V2_YAZMA_AKTIF arkasinda.
 *
 *   POST /patron-asistan/sor {salon_id, metin}             → {basarili, cevap, seslendir, kart, intent, aksiyon?}
 *   POST /patron-asistan/uygula {salon_id, tur, tip, oran} → {basarili, cevap, seslendir, kart, intent}
 */
class PatronAsistanController extends V2Controller
{
    private const YETKI = 'rapor.ciro_kar_gor';

    protected ?string $yazmaAlani = 'bildirim_reklam';

    public function __construct(private PatronAsistanAkisi $akis) {}

    public function sor(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $metin = trim((string) $request->input('metin', ''));
        if ($metin === '') {
            throw new GecersizIslemException('Bir şey sormadınız. Örn: "bugün kasa ne durumda?"');
        }

        return ApiCevap::basarili($this->akis->sor($salonId, $uid, $metin));
    }

    public function uygula(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->akis->uygula($salonId, $uid, (string) $request->input('tur', ''), (string) $request->input('tip', ''), (int) $request->input('oran', 0)));
    }
}
