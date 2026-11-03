<?php

namespace App\Domain\Ayar;

/**
 * Salon temasi (Flutter "Görünüm") config → mini site seed rengi — SAF mantik.
 *
 * Eski App\Helpers\SalonTema ile BIREBIR (mini site salonlar.tema_seed'i okur). Kaynak:
 * Flutter lib/theme/app_theme.dart AppThemeCatalog.presets; preset eklenirse UC yer birlikte.
 */
final class SalonTemaSeed
{
    public const PRESET_SEED = [
        'mor_luks'         => '#7C3AED',
        'pastel_lavanta'   => '#B794F4',
        'pastel_seftali'   => '#FB923C',
        'pastel_mint'      => '#5EEAD4',
        'pastel_bebe_mavi' => '#93C5FD',
        'pastel_pembe'     => '#F9A8D4',
        'pastel_sari'      => '#FCD34D',
        'okyanus'          => '#0EA5E9',
        'yesil_naturel'    => '#10B981',
        'gul_pembesi'      => '#EC4899',
        'altin_karamel'    => '#D97706',
        'mercan_corali'    => '#FB7185',
        'siyah_beyaz'      => '#1F2937',
        'gri'              => '#6B7280',
        'antrasit'         => '#334155',
        'klasik_koyu'      => '#6366F1',
    ];

    public const OZEL = 'ozel';
    public const VARSAYILAN_PRESET = 'mor_luks';
    public const VARSAYILAN_SEED = '#5C008E';
    public const PARLAKLIK_MODLARI = ['light', 'dark', 'system'];

    /** Varsayilan config (kayit yoksa) — eski salonTemaGet ile ayni. */
    public static function varsayilan(): array
    {
        return ['preset' => self::VARSAYILAN_PRESET, 'brightnessMode' => 'light'];
    }

    public static function presetGecerli(string $preset): bool
    {
        return $preset === self::OZEL || isset(self::PRESET_SEED[$preset]);
    }

    /** {preset, customSeedArgb?, brightnessMode} → '#RRGGBB' (gecersizse varsayilan mor). */
    public static function seed(array $config): string
    {
        $preset = strtolower(trim((string) ($config['preset'] ?? self::VARSAYILAN_PRESET)));
        if ($preset === self::OZEL) {
            return self::argbHex($config['customSeedArgb'] ?? null) ?? self::VARSAYILAN_SEED;
        }

        return self::PRESET_SEED[$preset] ?? self::VARSAYILAN_SEED;
    }

    /** ARGB int (0xAARRGGBB) → '#RRGGBB'; gecersizse null. */
    public static function argbHex($argb): ?string
    {
        if ($argb === null || $argb === '' || ! is_numeric($argb)) {
            return null;
        }
        $argb = (int) $argb;

        return sprintf('#%02X%02X%02X', ($argb >> 16) & 0xFF, ($argb >> 8) & 0xFF, $argb & 0xFF);
    }
}
