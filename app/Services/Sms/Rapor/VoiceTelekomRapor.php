<?php

namespace App\Services\Sms\Rapor;

use App\Domain\Sms\SmsDurumu;
use App\Domain\Sms\SmsTuru;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VoiceTelekom raporu (salonlar.yeni_sms=1) — eski SMSController::voiceTelekomRaporlariGetir /
 * RaporDetayGetir karşılığı, aynı uçlar: POST sms/list, sms/list-item.
 *
 * Sağlayıcıda tür filtresi yok, son N günün TÜM paketleri inmek zorunda (20.000'e kadar). Eski kod bunu
 * HER tablo sayfasında yapıyordu (beyaz sayfanın da kök nedeni); burada paketler türe ayrılıp ayrı ayrı
 * önbelleğe yazılır, istek yalnız kendi türünü okur. Başarısız çekim önbelleğe yazılmaz.
 */
class VoiceTelekomRapor implements SmsRaporSaglayici
{
    private const SIRALAMA = ['tarih' => 'ts', 'adet' => 'count', 'kredi' => 'toplam_kredi'];

    public function paketler(object $salon, SmsTuru $tur, array $liste): array
    {
        $satirlar = $this->tureGore($salon, $tur);

        if ($liste['ara'] ?? null) {
            $satirlar = array_values(array_filter($satirlar, fn ($s) => mb_stripos((string) $s['msgdetails'], $liste['ara']) !== false));
        }
        $alan = self::SIRALAMA[$liste['sirala'] ?? 'tarih'] ?? 'ts';
        $yon = ($liste['yon'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($satirlar, fn ($a, $b) => ($a[$alan] <=> $b[$alan]) * $yon);

        $bas = ($liste['page'] - 1) * $liste['per_page'];

        return [
            'satirlar' => array_map(function ($s) { unset($s['ts']); return $s; }, array_slice($satirlar, $bas, $liste['per_page'])),
            'toplam'   => count($satirlar),
        ];
    }

    public function alicilar(object $salon, string $paketId): array
    {
        $veri = $this->istek($salon, 'sms/list-item', ['pkgID' => $paketId, 'pageIndex' => 0, 'pageSize' => (int) config('sms.voicetelekom.rapor_sayfa')]);

        return array_map(fn ($a) => [
            'telefon'       => (string) ($a['target'] ?? ''),
            'operator'      => (string) ($a['operator'] ?? ''),
            'durum'         => SmsDurumu::voiceTelekomAlici((int) ($a['state'] ?? -99)),
            'iletim_tarihi' => self::tarih($a['deliveryDate'] ?? $a['processingDate'] ?? $a['sendingDate'] ?? null),
        ], $veri['list'] ?? []);
    }

    public function unut(object $salon): void
    {
        foreach (SmsTuru::cases() as $t) {
            Cache::forget($this->anahtar($salon, $t));
        }
    }

    /** @return array<int,array> türün tüm satırları (önbellekten; yoksa sağlayıcıdan iner). */
    private function tureGore(object $salon, SmsTuru $tur): array
    {
        $onbellek = Cache::get($this->anahtar($salon, $tur));
        if (is_array($onbellek)) {
            return $onbellek;
        }
        $gruplar = $this->hepsiniIndir($salon);
        foreach (SmsTuru::cases() as $t) {
            Cache::put($this->anahtar($salon, $t), $gruplar[$t->value] ?? [], (int) config('sms.rapor_cache_sn'));
        }

        return $gruplar[$tur->value] ?? [];
    }

    /** @return array<int, array<int,array>> tur değeri → satırlar */
    private function hepsiniIndir(object $salon): array
    {
        $gruplar = [];
        $sayfaBoyu = (int) config('sms.voicetelekom.rapor_sayfa');
        $bitis = now()->format('Y-m-d H:i');
        $baslangic = now()->subDays((int) config('sms.rapor_gun'))->format('Y-m-d H:i');
        $inen = 0;

        for ($sayfa = 0; $sayfa < (int) config('sms.voicetelekom.rapor_maks_sayfa'); $sayfa++) {
            try {
                $veri = $this->istek($salon, 'sms/list', ['startDate' => $baslangic, 'finishDate' => $bitis, 'pageIndex' => $sayfa, 'pageSize' => $sayfaBoyu]);
            } catch (SaglayiciHatasi $e) {
                if ($sayfa === 0) {
                    throw $e;
                }
                break; // eski davranış: ilk sayfalar indiyse eldekiyle devam
            }
            $gelen = $veri['list'] ?? [];
            foreach ($gelen as $p) {
                $gruplar[SmsTuru::basliktan($p['title'] ?? null)->value][] = self::satir($p);
            }
            $inen += count($gelen);
            if (count($gelen) < $sayfaBoyu || $inen >= (int) ($veri['stats']['totalRecord'] ?? PHP_INT_MAX)) {
                break;
            }
        }

        return $gruplar;
    }

    /** Sağlayıcı paketi → ortak satır (+ sıralama için ts). */
    private static function satir(array $p): array
    {
        $ham = ($p['processingDate'] ?? null) ?: (($p['sendingDate'] ?? null) ?: ($p['processInfo']['create']['date'] ?? null));
        $adet = (int) ($p['statistics']['total'] ?? 0);
        $kredi = (float) ($p['statistics']['credit'] ?? 0);

        return [
            'id'           => (string) ($p['id'] ?? ''),
            'date'         => self::tarih($ham),
            'ts'           => $ham ? (int) strtotime((string) $ham) : 0,
            'count'        => $adet,
            'price'        => $adet > 0 ? round($kredi / $adet, 4) : $kredi,
            'toplam_kredi' => $kredi,
            'msgdetails'   => (string) ($p['content'] ?? ''),
            'status'       => SmsDurumu::voiceTelekomPaket((int) ($p['state'] ?? 0), (int) ($p['statistics']['delivered'] ?? 0)),
        ];
    }

    private static function tarih(mixed $ham): string
    {
        $ts = $ham ? strtotime((string) $ham) : false;

        return $ts ? date('d.m.Y H:i:s', $ts) : (string) ($ham ?? '');
    }

    /** @throws SaglayiciHatasi */
    private function istek(object $salon, string $yol, array $govde): array
    {
        try {
            $cevap = $this->http($salon)->post(rtrim((string) config('sms.voicetelekom.rapor_url'), '/').'/'.$yol, $govde)->json();
        } catch (\Throwable $e) {
            Log::warning('VoiceTelekom SMS raporu alinamadi', ['salon' => $salon->id ?? null, 'yol' => $yol, 'hata' => $e->getMessage()]);
            throw new SaglayiciHatasi('SMS sağlayıcısına ulaşılamadı');
        }
        if (! is_array($cevap) || ($cevap['err'] ?? null) !== null || ! is_array($cevap['data'] ?? null)) {
            Log::warning('VoiceTelekom SMS raporu hatasi', ['salon' => $salon->id ?? null, 'yol' => $yol, 'err' => $cevap['err'] ?? null]);
            throw new SaglayiciHatasi((string) ($cevap['err']['message'] ?? 'SMS sağlayıcısı rapor vermedi'));
        }

        return $cevap['data'];
    }

    /** Eski istemci gibi sertifika doğrulaması kapalı: sağlayıcının 9588 portu IP/özel sertifikalı. */
    private function http(object $salon): PendingRequest
    {
        return Http::timeout((int) config('sms.rapor_zaman_asimi_sn'))
            ->withoutVerifying()
            ->withBasicAuth((string) ($salon->sms_user_name ?? ''), (string) ($salon->sms_secret ?? ''))
            ->acceptJson();
    }

    private function anahtar(object $salon, SmsTuru $tur): string
    {
        return 'sms.rapor.'.$salon->id.'.'.$tur->kod();
    }
}
