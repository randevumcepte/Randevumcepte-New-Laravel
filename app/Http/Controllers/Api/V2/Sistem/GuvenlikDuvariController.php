<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\GuvenlikDuvariServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 *   GET    /sistem/guvenlik-duvari              → {watchdog, ozet}
 *   GET    /sistem/guvenlik-duvari/engelliler   + ListeIstegi
 *   GET    /sistem/guvenlik-duvari/kurallar?tip=whitelist|blacklist + ListeIstegi
 *   GET    /sistem/guvenlik-duvari/olaylar      + ListeIstegi (son 100)
 *   POST   /sistem/guvenlik-duvari/engel-kaldir {ip}
 *   POST   /sistem/guvenlik-duvari/whitelist    {ip, aciklama?}
 *   POST   /sistem/guvenlik-duvari/blacklist    {ip, aciklama?}
 *   DELETE /sistem/guvenlik-duvari/kural/{id}
 */
class GuvenlikDuvariController extends SistemTabanController
{
    public function __construct(private GuvenlikDuvariServisi $servis) {}

    public function ozet()
    {
        return ApiCevap::basarili($this->servis->ozet());
    }

    public function engelliler(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(GuvenlikDuvariServisi::ENGELLI_SIRALAMA), 'zaman', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->engelliler($liste));
    }

    public function kurallar(Request $request)
    {
        $g = $this->alanlariDogrula($request, ['tip' => ['required', 'in:whitelist,blacklist']]);
        $liste = ListeIstegi::istekten($request, 10, array_keys(GuvenlikDuvariServisi::KURAL_SIRALAMA), 'zaman', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->kurallar($g['tip'], $liste));
    }

    public function olaylar(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 25, array_keys(GuvenlikDuvariServisi::OLAY_SIRALAMA), 'zaman', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->olaylar($liste));
    }

    public function engelKaldir(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->alanlariDogrula($request, ['ip' => ['required', 'string', 'max:45']]);

        return ApiCevap::basarili(null, $this->servis->engelKaldir($g['ip'], $this->yonetici()));
    }

    public function whitelist(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->kuralGirdisi($request);

        return ApiCevap::basarili(null, $this->servis->whitelistEkle($g['ip'], $g['aciklama'] ?? null, $this->yonetici()));
    }

    public function blacklist(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->kuralGirdisi($request);

        return ApiCevap::basarili(null, $this->servis->blacklistEkle($g['ip'], $g['aciklama'] ?? null, $this->yonetici()));
    }

    public function kuralSil(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->kuralSil($id);

        return ApiCevap::basarili(null, 'Kural silindi.');
    }

    private function kuralGirdisi(Request $request): array
    {
        return $this->alanlariDogrula($request, [
            'ip'       => ['required', 'string', 'max:45'],
            'aciklama' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
