<?php

namespace App\Services\PatronAsistan;

use App\Services\SistemYonetim\Entegrasyon\AiKrediServisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Patron asistaninin Anthropic istemcisi: tek cagri noktasi, kullanim kaydi burada. Anahtar ve model
 * config/sistem-asistan.php'den. Hata yutulur (null), cagiran kural motoruna duser.
 */
class PatronAsistanAi
{
    public function __construct(private AiKrediServisi $kredi) {}

    public function anahtarVar(): bool
    {
        return (string) config('sistem-asistan.anahtar') !== '';
    }

    public function model(): string
    {
        return (string) (config('sistem-asistan.model') ?: 'claude-haiku-4-5');
    }

    /** @return array<string,mixed>|null Anthropic cevabi; icerik yoksa veya hata olursa null. */
    public function cagir(array $govde, int $zamanAsimi, string $tur, int $salonId): ?array
    {
        try {
            $cevap = Http::timeout($zamanAsimi)
                ->withHeaders(['x-api-key' => (string) config('sistem-asistan.anahtar'), 'anthropic-version' => '2023-06-01'])
                ->post((string) config('sistem-asistan.url'), $govde);
            $data = $cevap->successful() ? $cevap->json() : null;
            if (! is_array($data) || empty($data['content'])) {
                return null;
            }
            $this->kredi->kullanimYaz($salonId ?: null, $tur, (int) ($data['usage']['input_tokens'] ?? 0), (int) ($data['usage']['output_tokens'] ?? 0), $govde['model'] ?? null);

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /** Cevaptaki metin bloklari birlesik; bossa null. */
    public function metin(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        $metin = '';
        foreach ($data['content'] as $blok) {
            if (($blok['type'] ?? '') === 'text') {
                $metin .= $blok['text'] ?? '';
            }
        }
        $metin = trim($metin);

        return $metin !== '' ? $metin : null;
    }

    /** Onbellekten donen cevabin kaydi (maliyetsiz). Ana akisi bozmaz. */
    public function onbellekYaz(int $salonId, string $tur): void
    {
        try {
            if (Schema::hasTable('ai_kullanim')) {
                DB::table('ai_kullanim')->insert([
                    'salon_id' => $salonId ?: null, 'tur' => $tur, 'model' => null, 'girdi_token' => 0, 'cikti_token' => 0,
                    'maliyet_usd' => 0, 'cache' => 1, 'basarili' => 1, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (Throwable) {
            // kayit tutulamazsa cevap yine doner
        }
    }
}
