<?php

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\Request;

/**
 * WhatsApp Yönetimi controller'larının ortak tabanı — yetki anahtarı ve kill-switch TEK yerde.
 * Yetki: eski sayfa anahtarı `pazarlama.whatsapp_gonder` (okuma + yazma). Yazmalar
 * WHATSAPP_V2_YAZMA_AKTIF arkasında (kapalıyken 409) — kapsam/yetki SONRASI.
 */
abstract class WhatsappTabanController extends V2Controller
{
    protected const YETKI = 'pazarlama.whatsapp_gonder';

    protected ?string $yazmaAlani = 'whatsapp';

    protected string $yazmaKapaliMesaj = 'WhatsApp v2 yazma şu an kapalı';

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    protected function salon(Request $request): array
    {
        return $this->salonDenetle($request, self::YETKI);
    }

    /** @return array{0:int,1:int} [yetkili id, salon id] */
    protected function yazilacakSalon(Request $request): array
    {
        $kimlik = $this->salon($request);
        $this->yazmaAcikMi();

        return $kimlik;
    }
}
