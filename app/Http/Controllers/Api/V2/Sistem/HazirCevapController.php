<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Domain\SistemYonetim\HazirCevapKategorisi;
use App\Services\SistemYonetim\Destek\HazirCevapServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET    /sistem/hazir-cevap/secenekler      → {kategoriler, yazabilir}
 *   GET    /sistem/hazir-cevap + ListeIstegi (sirala: kullanim|baslik|kategori|kisayol|durum)
 *   GET    /sistem/hazir-cevap/secim?q=&kategori= → yanit formundaki secici (aktifler, en fazla 30)
 *   POST   /sistem/hazir-cevap, PUT|DELETE /{id}, POST /{id}/kullan
 */
class HazirCevapController extends DestekTabanController
{
    public function __construct(private HazirCevapServisi $servis) {}

    public function secenekler()
    {
        return ApiCevap::basarili($this->servis->secenekler() + ['yazabilir' => $this->izinli(SistemYetki::ISLEM)]);
    }

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(HazirCevapServisi::SIRALAMA), 'kullanim', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->listele($liste));
    }

    public function secim(Request $request)
    {
        $g = $this->dogrula($request, ['q' => ['nullable', 'string', 'max:100'], 'kategori' => ['nullable', Rule::enum(HazirCevapKategorisi::class)]]);

        return ApiCevap::basarili($this->servis->secim($g['q'] ?? null, $g['kategori'] ?? null));
    }

    public function olustur(Request $request)
    {
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['id' => $this->servis->olustur($this->girdi($request), $this->yonetici())], 'Hazır cevap eklendi.', 201);
    }

    public function guncelle(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->guncelle($id, $this->girdi($request));

        return ApiCevap::basarili(null, 'Güncellendi.');
    }

    public function sil(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->sil($id);

        return ApiCevap::basarili(null, 'Silindi.');
    }

    public function kullan(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->kullanimSay($id);

        return ApiCevap::basarili();
    }

    private function girdi(Request $request): array
    {
        return $this->dogrula($request, [
            'baslik'   => ['required', 'string', 'min:2', 'max:200'],
            'icerik'   => ['required', 'string', 'min:2', 'max:20000'],
            'kategori' => ['required', Rule::enum(HazirCevapKategorisi::class)],
            'kisayol'  => ['nullable', 'string', 'max:30'],
            'aktif'    => ['nullable', 'boolean'],
        ]);
    }
}
