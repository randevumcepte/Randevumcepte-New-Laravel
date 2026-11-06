<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Services\SistemYonetim\Icerik\ManuelOdemeServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 *   GET  /sistem/manuel-odeme/secenekler → {yazabilir}
 *   POST /sistem/manuel-odeme {salon_id, hizmetler:[{ad, adet, tutar}], notlar?} → {form_id, link, toplam}
 */
class ManuelOdemeController extends DestekTabanController
{
    public function __construct(private ManuelOdemeServisi $servis) {}

    public function secenekler()
    {
        return ApiCevap::basarili(['yazabilir' => $this->izinli(SistemYetki::ISLEM)]);
    }

    public function olustur(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'salon_id'         => ['required', 'integer', 'min:1'],
            'hizmetler'        => ['required', 'array', 'min:1', 'max:50'],
            'hizmetler.*.ad'   => ['nullable', 'string', 'max:255'],
            'hizmetler.*.adet' => ['nullable', 'integer', 'max:100000'],
            'notlar'           => ['nullable', 'string', 'max:5000'],
        ]);
        $salon = $this->salon((int) $g['salon_id'], ['id', 'salon_adi']);

        return ApiCevap::basarili($this->servis->olustur($salon, $request->input('hizmetler'), $g['notlar'] ?? null), 'Ödeme linki oluşturuldu.', 201);
    }
}
