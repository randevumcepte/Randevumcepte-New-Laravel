<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Online\VitrinCarkServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Vitrin hediye carki, MISAFIR yolu (kimliksiz; mini site + uygulama). Cark cizim verisi GET /vitrin/{salonId} 'cark'.
 * Giris yapmis musteri /musteri-uygulama/cark ve /cark/cevir uclarini kullanir.
 *
 *   POST /vitrin/{salonId}/cark/cevir                                   → {dilimIndex, dilim, odulKodu:null, kayitGerekli, taslak, kalanHak, sonrakiHakTarihi}
 *   POST /vitrin/{salonId}/cark/kod {taslak, ad, soyad, cep_telefon, kvkk, _hp?, form_sure_sn?}  → {telefon}
 *   POST /vitrin/{salonId}/cark/dogrula {taslak, kod}                   → {dilimIndex, dilim, odulKodu, kalanHak, yeniUye, oturum, ...}
 */
class VitrinCarkController extends Controller
{
    public function __construct(private VitrinCarkServisi $servis) {}

    public function cevir(Request $request, int $salonId)
    {
        return ApiCevap::basarili($this->servis->cevir($salonId, (string) $request->ip()));
    }

    public function kodGonder(Request $request, int $salonId)
    {
        $g = $request->validate([
            'taslak'       => ['required', 'string', 'max:64'],
            'ad'           => ['required', 'string', 'max:50'],
            'soyad'        => ['required', 'string', 'max:50'],
            'cep_telefon'  => ['required', 'string', 'max:30'],
            'kvkk'         => ['accepted'],
            '_hp'          => ['nullable', 'string'],
            'form_sure_sn' => ['nullable', 'integer'],
        ]);

        return ApiCevap::basarili($this->servis->kodGonder($salonId, $g, (string) $request->ip()), 'Telefonunuza 4 haneli kod gönderildi. Lütfen kodu girin.');
    }

    public function kodDogrula(Request $request, int $salonId)
    {
        $g = $request->validate(['taslak' => ['required', 'string', 'max:64'], 'kod' => ['required', 'string', 'max:10']]);

        return ApiCevap::basarili($this->servis->kodDogrula($salonId, $g['taslak'], $g['kod']));
    }
}
