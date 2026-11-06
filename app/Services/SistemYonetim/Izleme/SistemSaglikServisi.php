<?php

namespace App\Services\SistemYonetim\Izleme;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Veritabani, disk, surum ve bu uygulamanin laravel.log hatalari. Hata metni ham donmez: sir sizdirmamak icin kisaltilir. */
class SistemSaglikServisi
{
    private const LOG_KUYRUK_BAYT = 50000;

    private const LOG_SATIR_SINIRI = 250;

    private const LOG_ADET_SINIRI = 30;

    public function rapor(): array
    {
        $diskToplam = (float) (@disk_total_space(base_path()) ?: 0);
        $diskBos = (float) (@disk_free_space(base_path()) ?: 0);

        return [
            'veritabani'     => $this->veritabani(),
            'php_surum'      => PHP_VERSION,
            'laravel_surum'  => app()->version(),
            'disk'           => [
                'toplam_gb' => round($diskToplam / 1073741824, 1),
                'bos_gb'    => round($diskBos / 1073741824, 1),
                'kullanim'  => $diskToplam > 0 ? round(($diskToplam - $diskBos) / $diskToplam * 100, 1) : 0,
            ],
            'whatsapp_aktif' => $this->whatsappAktifSalon(),
            'log_hatalari'   => self::logHatalari(storage_path('logs/laravel.log')),
        ];
    }

    /**
     * Dosyanin sonundan ERROR/CRITICAL satirlari, en yenisi once. Kuyruk cok baytli karakterin ortasindan basladiginda
     * bozuk UTF-8 JSON'u dusurmesin diye temizlenir.
     *
     * @return list<string>
     */
    public static function logHatalari(string $yol): array
    {
        $boyut = is_file($yol) ? (int) filesize($yol) : 0;
        $dosya = $boyut > 0 ? @fopen($yol, 'r') : false;
        if (! $dosya) {
            return [];
        }
        if ($boyut > self::LOG_KUYRUK_BAYT) {
            fseek($dosya, $boyut - self::LOG_KUYRUK_BAYT);
        }

        $hatalar = [];
        while (($satir = fgets($dosya)) !== false) {
            if (str_contains($satir, '.ERROR') || str_contains($satir, '.CRITICAL')) {
                $hatalar[] = mb_substr(rtrim(mb_scrub($satir, 'UTF-8')), 0, self::LOG_SATIR_SINIRI);
            }
        }
        fclose($dosya);

        return array_slice(array_reverse($hatalar), 0, self::LOG_ADET_SINIRI);
    }

    /** @return array{durum:string,surum:?string} */
    private function veritabani(): array
    {
        try {
            return ['durum' => 'OK', 'surum' => DB::selectOne('SELECT VERSION() AS v')->v ?? null];
        } catch (\Throwable $e) {
            Log::warning('Sistem sagligi: veritabani denetimi basarisiz', ['hata' => $e->getMessage()]);

            return ['durum' => 'HATA', 'surum' => null];
        }
    }

    private function whatsappAktifSalon(): int
    {
        try {
            return DB::table('salonlar')->where('whatsapp_aktif', 1)->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
