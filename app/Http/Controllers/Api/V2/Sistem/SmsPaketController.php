<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Domain\SistemYonetim\SmsPaketKartRengi;
use App\Services\SistemYonetim\Icerik\SmsPaketServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET    /sistem/sms-paket/secenekler → {kart_renkleri, kdv_yuzde, yazabilir}
 *   GET    /sistem/sms-paket + ListeIstegi (sirala: sms_adet|ucret|class)
 *   POST   /sistem/sms-paket, PUT|DELETE /{id}
 */
class SmsPaketController extends DestekTabanController
{
    public function __construct(private SmsPaketServisi $servis) {}

    public function secenekler()
    {
        return ApiCevap::basarili($this->servis->secenekler() + ['yazabilir' => $this->izinli(SistemYetki::YONETIM)]);
    }

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(SmsPaketServisi::SIRALAMA), 'sms_adet', 'asc')->dizi();

        return ApiCevap::basarili($this->servis->listele($liste));
    }

    public function olustur(Request $request)
    {
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['id' => $this->servis->olustur($this->girdi($request))], 'Paket oluşturuldu.', 201);
    }

    public function guncelle(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->guncelle($id, $this->girdi($request));

        return ApiCevap::basarili(null, 'Paket güncellendi.');
    }

    public function sil(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->sil($id);

        return ApiCevap::basarili(null, 'Paket silindi.');
    }

    private function girdi(Request $request): array
    {
        return $this->dogrula($request, [
            'sms_adet'   => ['required', 'integer', 'min:1'],
            'ucret'      => ['required', 'numeric', 'min:0'],
            'class'      => ['nullable', Rule::enum(SmsPaketKartRengi::class)],
            'paket_adi'  => ['nullable', 'string', 'max:255'],
            'alt_baslik' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
