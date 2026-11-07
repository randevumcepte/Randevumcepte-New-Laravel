<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Whatsapp\KontorPaketleri;
use App\Exceptions\BulunamadiException;
use App\Services\Whatsapp\WhatsappKontorServisi;
use App\Services\Yetki\GuvenlikKapisi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Mobil WebView kontor ekrani (eski StoreAdminController::whatsappKontorMobil + whatsappPaketTalep). Kimlik imzali
 * adresten gelir, oturum acilmaz; kapsam ve yetki her istekte yeniden denetlenir. Bakiye eski ekrandaki gibi salonun
 * kendi satirindan okunur.
 */
class WhatsappKontorSayfaController extends WhatsappTabanController
{
    private const TALEP_DAKIKA = 30;

    private const TEKRAR_KILIT_SN = 10;

    public function __construct(private GuvenlikKapisi $kapi) {}

    public function goster(Request $r)
    {
        [$uid, $salonId] = $this->imzaliKimlik($r);
        $salon = DB::table('salonlar')->where('id', $salonId)->first() ?? throw new BulunamadiException('Salon bulunamadı');

        return response()->view('v2.whatsapp-kontor-mobil', [
            'isletme'     => $salon,
            'talepAdresi' => URL::temporarySignedRoute('v2.whatsapp-kontor.talep', now()->addMinutes(self::TALEP_DAKIKA), ['uid' => $uid, 'salon' => $salonId], false),
        ]);
    }

    /** Sayfa `{ok, mesaj}` okur. Cift tiklama ayni bildirimi iki kez gondermez. */
    public function talep(Request $r, WhatsappKontorServisi $kontor)
    {
        [$uid, $salonId] = $this->imzaliKimlik($r);
        $this->yazmaAcikMi();
        $v = $r->validate([
            'paket'    => ['required', 'string', Rule::in(KontorPaketleri::kodlar())],
            'iletisim' => ['nullable', 'string', 'max:200'],
        ]);
        if (Cache::add('wa.kontor.talep.'.$salonId.'.'.$v['paket'], 1, self::TEKRAR_KILIT_SN)) {
            $kontor->talep($salonId, $uid, $v['paket'], $v['iletisim'] ?? null);
        }

        return response()->json(['ok' => true, 'mesaj' => 'Talebiniz alındı. En kısa sürede sizinle iletişime geçeceğiz.']);
    }

    /** @return array{0:int,1:int} */
    private function imzaliKimlik(Request $r): array
    {
        $uid = (int) $r->query('uid');
        $salonId = (int) $r->query('salon');
        $this->kapi->denetle($uid, $salonId, self::YETKI);

        return [$uid, $salonId];
    }
}
