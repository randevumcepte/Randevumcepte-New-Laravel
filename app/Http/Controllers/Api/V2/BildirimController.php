<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Bildirim\CihazSahibi;
use App\Services\Bildirim\BildirimKutusu;
use App\Services\Bildirim\BildirimKutusuFabrikasi;
use App\Services\Bildirim\BildirimYazServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * BİLDİRİMLER v2 — panel ve Flutter yönetici. Kimlik token'dan, kapsam salon_id'den; bildirim kişisel
 * olduğu için menü yetkisi aranmaz. v1 → v2 eşlemesi: docs/strangler-bildirim.md
 *
 *   GET    /bildirim?salon_id=&filtre=okunmamis + ListeIstegi → {data[], sayac{okunmamis,toplam}, sayfalama}
 *   GET    /bildirim/sayac?salon_id=                          → {okunmamis, toplam}
 *   POST   /bildirim/{id}/okundu          POST   /bildirim/tumu/okundu
 *   DELETE /bildirim/{id}                 DELETE /bildirim
 *   POST   /bildirim/cihaz {salon_id, token, platform, cihaz?, app_bundle?}
 *   DELETE /bildirim/cihaz {salon_id, token}
 *   GET    /bildirim/web-ayar             → tarayıcı push ayarı (herkese açık anahtarlar) | null
 */
class BildirimController extends V2Controller
{
    use BildirimKutusuUclari;

    protected ?string $yazmaAlani = 'bildirim';

    protected string $yazmaKapaliMesaj = 'Bildirim v2 yazma şu an kapalı';

    public function __construct(private BildirimKutusuFabrikasi $fabrika) {}

    public function sil(Request $request, BildirimYazServisi $yaz, int $id)
    {
        $this->yazmaAcikMi();

        return ApiCevap::basarili($yaz->sil($this->kutu($request), $id));
    }

    public function tumunuSil(Request $request, BildirimYazServisi $yaz)
    {
        $this->yazmaAcikMi();

        return ApiCevap::basarili($yaz->tumunuSil($this->kutu($request)), 'Tüm bildirimler silindi');
    }

    public function webAyar()
    {
        $this->kullanici();
        $ayar = (array) config('bildirim.web_push');

        return ApiCevap::basarili(! empty($ayar['apiKey']) && ! empty($ayar['vapidKey']) ? $ayar : null);
    }

    protected function kutu(Request $request): BildirimKutusu
    {
        [$yetkiliId, $salonId] = $this->salonDenetle($request, []);

        return $this->fabrika->personel($yetkiliId, $salonId);
    }

    protected function cihazSahibi(Request $request): CihazSahibi
    {
        [$yetkiliId, $salonId] = $this->salonDenetle($request, []);

        return $this->fabrika->personelCihazi($yetkiliId, $salonId);
    }

    protected function yazmaKapisi(): void
    {
        $this->yazmaAcikMi();
    }
}
