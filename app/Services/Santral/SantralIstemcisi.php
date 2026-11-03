<?php

namespace App\Services\Santral;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Santral (FreePBX) izleme API istemcisi — dis HTTP cagrilarinin TEK noktasi.
 * Is kurali icermez; yalnizca istek + onbellek + hata yutma (santral erisilemezse
 * ekran bos liste + hata=true ile acilir, 500 vermez).
 */
class SantralIstemcisi
{
    /**
     * CDR listesi (tarih araligi, salonun dahilileri + trunk'i).
     *
     * @param array<int,string> $dahililer
     * @return array{satirlar: array<int,array>, toplam: ?int, hata: bool}
     */
    public function cdrListesi(array $dahililer, ?string $did, string $tarih1, string $tarih2, int $limit, int $offset = 0): array
    {
        $sorgu = array_filter([
            'limit'     => $limit,
            'offset'    => $offset,
            'dahililer' => array_values($dahililer),
            'tarih1'    => $tarih1,
            'tarih2'    => $tarih2,
            'did'       => $did,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->onbellekli('santral.cdr.', $sorgu, (int) config('santral.cdr_cache_sn'), function () use ($sorgu) {
            $govde = $this->getir((string) config('santral.cdr_url'), $sorgu);
            if ($govde === null) {
                return ['satirlar' => [], 'toplam' => null, 'hata' => true];
            }
            // Yeni format {data,total,has_more}; eski format duz dizi.
            $satirlar = isset($govde['data']) && is_array($govde['data']) ? $govde['data'] : $govde;

            return [
                'satirlar' => array_values(array_filter($satirlar, 'is_array')),
                'toplam'   => isset($govde['total']) ? (int) $govde['total'] : null,
                'hata'     => false,
            ];
        });
    }

    /**
     * Giden konusma dakikasi ozeti (trunk bazli SUM(billsec); dahili verilirse daraltilir).
     *
     * @return array{dakika: float, adet: int, hata: bool}
     */
    public function dakikaOzeti(string $did, string $tarih1, string $tarih2, ?string $dahili = null): array
    {
        $sorgu = array_filter(['did' => $did, 'tarih1' => $tarih1, 'tarih2' => $tarih2, 'dahili' => $dahili], fn ($v) => $v !== null && $v !== '');

        return $this->onbellekli('santral.dakika.', $sorgu, (int) config('santral.dakika_cache_sn'), function () use ($sorgu) {
            $govde = $this->getir((string) config('santral.dakika_url'), $sorgu);
            if ($govde === null || isset($govde['error'])) {
                return ['dakika' => 0.0, 'adet' => 0, 'hata' => true];
            }

            return [
                'dakika' => (float) ($govde['toplam_dakika'] ?? 0),
                'adet'   => (int) ($govde['giden_cevaplanan_adet'] ?? 0),
                'hata'   => false,
            ];
        });
    }

    /** JSON GET; ag/HTTP/ayristirma hatasinda null (loglanir). */
    private function getir(string $url, array $sorgu): ?array
    {
        try {
            $cevap = Http::timeout((int) config('santral.zaman_asimi_sn', 20))->acceptJson()->get($url, $sorgu);
            if (! $cevap->successful()) {
                Log::warning('Santral API HTTP '.$cevap->status(), ['url' => $url]);

                return null;
            }
            $govde = $cevap->json();

            return is_array($govde) ? $govde : null;
        } catch (\Throwable $e) {
            Log::warning('Santral API erisilemedi', ['url' => $url, 'hata' => $e->getMessage()]);

            return null;
        }
    }

    /** Basarili cevaplari onbellege alir; hata cevabi onbellege YAZILMAZ (bir sonraki istek yeniden dener). */
    private function onbellekli(string $onek, array $sorgu, int $sn, callable $uret): array
    {
        $anahtar = $onek.md5(json_encode($sorgu));
        $onceki = Cache::get($anahtar);
        if (is_array($onceki)) {
            return $onceki;
        }
        $sonuc = $uret();
        if (empty($sonuc['hata']) && $sn > 0) {
            Cache::put($anahtar, $sonuc, $sn);
        }

        return $sonuc;
    }
}
