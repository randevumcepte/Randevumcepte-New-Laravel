<?php

namespace App\Services\Panel;

use App\Services\Yetki\YetkiServisi;

/**
 * Menü öğesi görünürlük kuralı — sol menü (PanelMenu) ve header kısayolları (PanelKisayollar)
 * AYNI kuraldan geçer. Yalnız gereksiz öğe göstermemek içindir; asıl erişim denetimi uçlardadır.
 */
class MenuGorunurluk
{
    public function __construct(private YetkiServisi $yetki) {}

    /** @param array<string,mixed> $m öğe tanımı (yetki, ozellik, studyo_gizle, rol5_gizle) */
    public function goster(array $m, int $yetkiliId, int $salonId, object $salon, bool $rol5): bool
    {
        if (($m['rol5_gizle'] ?? false) && $rol5) {
            return false;
        }
        if (($m['studyo_gizle'] ?? false) && (int) ($salon->studyo_modu ?? 0) === 1) {
            return false;
        }
        if (! empty($m['ozellik']) && ! $this->ozellikVar($m['ozellik'], $salon)) {
            return false;
        }
        if (! empty($m['yetki'])) {
            foreach ((array) $m['yetki'] as $anahtar) {
                if ($this->yetki->menuYetki($yetkiliId, $salonId, $anahtar)) {
                    return true; // OR-gate
                }
            }

            return false;
        }

        return true;
    }

    private function ozellikVar(string $ozellik, object $salon): bool
    {
        return match ($ozellik) {
            'santral_aktif'       => (int) ($salon->santral_aktif ?? 0) === 1,
            'grup_dersi_aktif'    => (int) ($salon->grup_dersi_aktif ?? 0) === 1,
            'uyelik_turu_premium' => (int) ($salon->uyelik_turu ?? 0) > 2,
            'uyelik_turu_paketli' => (int) ($salon->uyelik_turu ?? 0) > 1,   // eski menu: Kasa Raporu
            default               => true,
        };
    }
}
