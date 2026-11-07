<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Bildirim\CihazSahibi;
use App\Http\Requests\Bildirim\VoipTokenIstegi;
use App\Services\Bildirim\BildirimKutusu;
use App\Services\Bildirim\BildirimKutusuFabrikasi;
use App\Services\Bildirim\BildirimYazServisi;
use App\Services\Bildirim\CihazKayitServisi;
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
 *   POST   /bildirim/voip-token {token, android_fcm_token?}
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

    /** Kimlik token'dan; hesabın tüm salonlardaki personel kayıtlarına yazılır. Salon istenmez. */
    public function voipKaydet(VoipTokenIstegi $request, CihazKayitServisi $cihazlar)
    {
        $this->yazmaAcikMi();
        $v = $request->validated();

        return ApiCevap::basarili(['guncellenen' => $cihazlar->voipKaydet((int) $this->kullanici()->id, $v['token'], $v['android_fcm_token'] ?? null)]);
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
