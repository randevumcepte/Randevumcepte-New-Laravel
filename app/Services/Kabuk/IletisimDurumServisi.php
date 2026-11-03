<?php

namespace App\Services\Kabuk;

use App\Domain\Whatsapp\WhatsappDurumu;
use App\Services\Sms\Bakiye\SmsBakiyeServisi;
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
        $salon = DB::table('salonlar')->where('id', $salonId)->first([
            'whatsapp_aktif', 'whatsapp_durum', 'whatsapp_numara', 'whatsapp_kontor', 'whatsapp_deneme_bitis',
        ]);
        if (! $salon) {
            return null;
        }

        return WhatsappDurumu::salondan($salon, date('Y-m-d'))->dizi()
            + ['url' => $this->menuUrl('whatsapp', $salonId)];
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
