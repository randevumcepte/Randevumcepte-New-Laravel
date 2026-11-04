<?php

namespace App\Services\Sms\Rapor;

use App\Domain\Sms\SmsDurumu;
use App\Domain\Sms\SmsTuru;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Efetech raporu (yeni_sms≠1) — paketler yerel sms_iletim_raporlari tablosunda (gönderimde yazılır),
 * alıcı detayı sağlayıcıdan (eski efetechRaporDetayGetir). Arama/sıralama/sayfalama SQL'de.
 */
class EfetechRapor implements SmsRaporSaglayici
{
    private const SIRALAMA = ['tarih' => 'updated_at', 'adet' => 'adet', 'kredi' => 'kredi'];

    public function paketler(object $salon, SmsTuru $tur, array $liste): array
    {
        $q = DB::table('sms_iletim_raporlari')->where('salon_id', $salon->id);
        // Eski kayıtlarda tur boş olabilir: bunlar Bildirim sayılır.
        $tur === SmsTuru::Bildirim
            ? $q->where(fn ($w) => $w->where('tur', $tur->value)->orWhere('tur', '')->orWhereNull('tur'))
            : $q->where('tur', $tur->value);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where('aciklama', 'like', $like);
        }
        $toplam = (clone $q)->count();

        $satirlar = $q->orderBy(self::SIRALAMA[$liste['sirala'] ?? 'tarih'] ?? 'updated_at', $liste['yon'] ?? 'desc')
            ->forPage($liste['page'], $liste['per_page'])
            ->get(['rapor_id', 'updated_at', 'adet', 'kredi', 'aciklama', 'durum'])
            ->map(fn ($r) => [
                'id'           => (string) $r->rapor_id,
                'date'         => $r->updated_at ? date('d.m.Y H:i:s', strtotime((string) $r->updated_at)) : '',
                'count'        => (int) $r->adet,
                'price'        => (float) $r->kredi,
                'toplam_kredi' => round((float) $r->kredi * (int) $r->adet, 4),
                'msgdetails'   => (string) $r->aciklama,
                'status'       => (int) $r->durum,
            ])->all();

        return ['satirlar' => $satirlar, 'toplam' => $toplam];
    }

    public function alicilar(object $salon, string $paketId): array
    {
        try {
            $cevap = Http::timeout((int) config('sms.rapor_zaman_asimi_sn'))
                ->withoutVerifying()
                ->withHeaders(['Authorization' => 'Key '.$salon->sms_apikey])
                ->acceptJson()
                ->post((string) config('sms.efetech.rapor_url'), ['id' => (int) $paketId])
                ->json();
        } catch (\Throwable $e) {
            Log::warning('Efetech SMS raporu alinamadi', ['salon' => $salon->id ?? null, 'hata' => $e->getMessage()]);
            throw new SaglayiciHatasi('SMS sağlayıcısına ulaşılamadı');
        }
        $detaylar = $cevap['response']['message']['details'] ?? null;
        if (empty($cevap['status']['successful']) || ! is_array($detaylar)) {
            throw new SaglayiciHatasi((string) ($cevap['status']['description'] ?? 'SMS sağlayıcısı rapor vermedi'));
        }

        return array_map(fn ($d) => [
            'telefon'       => (string) ($d['to'] ?? ''),
            'operator'      => '',
            'durum'         => SmsDurumu::efetechAlici((int) ($d['status'] ?? -1)),
            'iletim_tarihi' => ! empty($d['time']) ? date('d.m.Y H:i:s', (int) strtotime((string) $d['time'])) : '',
        ], $detaylar);
    }

    /** Veri yerel tabloda: önbellek yok. */
    public function unut(object $salon): void {}
}
