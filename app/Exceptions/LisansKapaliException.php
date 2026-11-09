<?php

namespace App\Exceptions;

use App\Domain\Lisans\LisansKurali;
use App\Support\ApiCevap;
use Illuminate\Http\JsonResponse;

/**
 * Salonun lisansi kapali → HTTP 402. Lisans kapisinin (middleware) ve kapsam gecidinin (GuvenlikKapisi::kapsamDenetle)
 * TEK cevabi: {basarili:false, mesaj, veri:{kod:'lisans_suresi_doldu', salon_id, uyelik_bitis_tarihi, aktif_olmadi}}.
 * salon_id null ise hangi salon oldugu soylenmez (salonsuz ya da bagli olmayan salonlu istek); Flutter null'da acik
 * paneli kapatir, web (v2-ortak.js) yalniz veri.kod'a bakar.
 */
class LisansKapaliException extends ApiException
{
    /** @param  array{kod:string,salon_id:?int,uyelik_bitis_tarihi:?string,aktif_olmadi:bool}  $veri  LisansKurali::cevapVerisi */
    public function __construct(public array $veri)
    {
        parent::__construct(LisansKurali::mesaj($veri['uyelik_bitis_tarihi'] ?? null), LisansKurali::HTTP_KODU);
    }

    public function render(): JsonResponse
    {
        return ApiCevap::hata($this->getMessage(), $this->durumKodu, $this->veri);
    }
}
