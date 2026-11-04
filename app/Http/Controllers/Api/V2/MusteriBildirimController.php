<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Bildirim\CihazSahibi;
use App\Services\Bildirim\BildirimKutusu;
use App\Services\Bildirim\BildirimKutusuFabrikasi;
use Illuminate\Http\Request;

/**
 * BİLDİRİMLER v2 — müşteri uygulaması ('auth:api'). Müşteri kimliği token'dan; gövdedeki user_id
 * yok sayılır. Kapsam: app_bundle (markanın tüm şubeleri) | salon_id. Eski adlar (appBundle, sube) kabul edilir.
 *
 *   GET    /musteri-uygulama/bildirimler + ListeIstegi, filtre=okunmamis
 *   GET    /musteri-uygulama/bildirimler/sayac
 *   POST   /musteri-uygulama/bildirimler/{id}/okundu    POST /musteri-uygulama/bildirimler/tumu/okundu
 *   POST   /musteri-uygulama/bildirimler/cihaz          DELETE /musteri-uygulama/bildirimler/cihaz
 */
class MusteriBildirimController extends MusteriUygulamaController
{
    use BildirimKutusuUclari;

    public function __construct(private BildirimKutusuFabrikasi $fabrika) {}

    protected function kutu(Request $request): BildirimKutusu
    {
        $bundle = $request->input('app_bundle', $request->input('appBundle'));

        return $this->fabrika->musteri($this->musteriId(), $this->salon($request), $bundle ? (string) $bundle : null);
    }

    protected function cihazSahibi(Request $request): CihazSahibi
    {
        return CihazSahibi::musteri($this->musteriId(), $this->salon($request));
    }

    protected function yazmaKapisi(): void {}

    private function salon(Request $request): ?int
    {
        return (int) $request->input('salon_id', $request->input('sube')) ?: null;
    }
}
