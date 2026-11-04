<?php

namespace App\Support;

/**
 * Eski monolit paneline MUTLAK link üretimi — TEK KAYNAK (config panel.eski_panel_url).
 *
 * Strangler geçişinde v2'de karşılığı henüz olmayan işlevler (ör. form PDF üretimi,
 * müşterinin form doldurma sayfaları) eski panelde kalır; v2 cevapları bu linkleri
 * hazır verir, istemciler host birleştirmez.
 */
final class EskiPanelLinki
{
    /** https://app.../yol?sorgu */
    public static function url(string $yol, array $sorgu = []): string
    {
        $url = rtrim((string) config('panel.eski_panel_url'), '/').'/'.ltrim($yol, '/');

        return $sorgu ? $url.'?'.http_build_query($sorgu) : $url;
    }

    /** Veritabanındaki göreli yol → mutlak URL (zaten mutlaksa aynen; boşsa null). */
    public static function mutlak(?string $yol): ?string
    {
        $yol = trim((string) $yol);
        if ($yol === '') {
            return null;
        }

        return preg_match('#^https?://#i', $yol) ? $yol : self::url(ltrim($yol, './'));
    }

    /** Host (SMS linkleri "https://{host}/..." biçiminde kurulur). */
    public static function host(): string
    {
        return (string) parse_url((string) config('panel.eski_panel_url'), PHP_URL_HOST);
    }
}
