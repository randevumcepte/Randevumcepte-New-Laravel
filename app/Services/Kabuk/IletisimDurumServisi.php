<?php

namespace App\Services\Kabuk;

use App\Services\Sms\Bakiye\SmsBakiyeServisi;
use App\Services\Whatsapp\WhatsappOturumServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\KapsamServisi;
use App\Support\PanelMenu;
use Illuminate\Support\Facades\DB;

/**
 * Header iletisim rozetleri: kalan SMS + WhatsApp baglanti/kontor durumu.
 * Eski layout'ta ikisi de personel rolune (5) gizliydi → burada null doner (istemci cizmez).
 * Rozet linki yalnizca ilgili ekranin v2 karsiligi varsa doner (eski panele yonlendirme yok).
 */
class IletisimDurumServisi
{
    public function __construct(
        private GuvenlikKapisi $kapi,
        private KapsamServisi $kapsam,
        private SmsBakiyeServisi $smsBakiye,
        private WhatsappOturumServisi $oturum,
    ) {}

    public function sms(int $yetkiliId, int $salonId): ?array
    {
        if (! $this->gorebilir($yetkiliId, $salonId)) {
            return null;
        }

        return [
            'kalan' => $this->smsBakiye->kalan($salonId),
            'url'   => $this->menuUrl('toplusms', $salonId),
        ];
    }

    public function whatsapp(int $yetkiliId, int $salonId): ?array
    {
        if (! $this->gorebilir($yetkiliId, $salonId)) {
            return null;
        }
        // Paylaşılan oturumda (ör. 416 → 246) bağlantı + kontör oturum SAHİBİ salondan (gönderimle aynı).
        $o = $this->oturum->coz($salonId);
        if (! $o) {
            return null;
        }

        return $o['durum']->dizi()
            + ['paylasilan' => $o['paylasilan'], 'url' => $this->menuUrl('whatsapp', $salonId)];
    }

    private function gorebilir(int $yetkiliId, int $salonId): bool
    {
        $this->kapi->kapsamDenetle($yetkiliId, $salonId);

        return ! in_array(5, $this->kapsam->salonRolleri($yetkiliId, $salonId), true);
    }

    private function menuUrl(string $slug, int $salonId): ?string
    {
        $m = PanelMenu::bul($slug);

        return $m ? PanelMenu::url($m, $salonId) : null;
    }
}
