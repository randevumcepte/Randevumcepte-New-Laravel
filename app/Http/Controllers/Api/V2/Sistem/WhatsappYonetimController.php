<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Domain\SistemYonetim\SistemYetki;
use App\Services\SistemYonetim\Entegrasyon\WhatsappPaketKatalogu;
use App\Services\SistemYonetim\Entegrasyon\WhatsappPanelIstatistik;
use App\Services\SistemYonetim\Entegrasyon\WhatsappSalonListesi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * WhatsApp Yonetim okuma, tum roller:
 *   GET /sistem/whatsapp-yonetim/secenekler  → paket, periyot, deneme, durum sozlukleri + izinler
 *   GET /sistem/whatsapp-yonetim/ozet        → bugun, hafta, ay, basariOrani, gunler, aktif_salon, ban_risk_salon
 *   GET /sistem/whatsapp-yonetim/dagilim?gun → tipler, en_cok
 *   GET /sistem/whatsapp-yonetim/salonlar?durum + ListeIstegi
 */
class WhatsappYonetimController extends SistemTabanController
{
    public function __construct(private WhatsappPanelIstatistik $istatistik, private WhatsappSalonListesi $salonlar) {}

    public function secenekler()
    {
        return ApiCevap::basarili(WhatsappPaketKatalogu::secenekler() + ['izinler' => [
            'log'         => $this->izinli(SistemYetki::ISLEM),
            'para'        => $this->izinli(SistemYetki::YONETIM),
            'hesaba_gir'  => $this->izinli(SistemYetki::ISLEM),
        ]]);
    }

    public function ozet()
    {
        return ApiCevap::basarili($this->istatistik->ozet());
    }

    public function dagilim(Request $request)
    {
        $g = $this->dogrula($request, ['gun' => ['nullable', 'integer', 'min:1', 'max:'.WhatsappPanelIstatistik::DAGILIM_MAKS_GUN]]);

        return ApiCevap::basarili($this->istatistik->dagilim((int) ($g['gun'] ?? 30)));
    }

    public function salonlar(Request $request)
    {
        $g = $this->dogrula($request, ['durum' => ['nullable', 'string', 'max:40']]);
        $liste = ListeIstegi::istekten($request, 25, array_keys(WhatsappSalonListesi::SIRALAMA), null, 'asc')->dizi();

        return ApiCevap::basarili($this->salonlar->liste($liste, $g['durum'] ?? null, $this->yonetici()));
    }
}
