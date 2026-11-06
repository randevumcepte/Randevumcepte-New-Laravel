<?php

namespace App\Services\SistemYonetim;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sistem yonetimi denetim izi — eski App\SistemYonetim\Audit ile AYNI tablo ve kolon anlami (eski panelin
 * Aktivite Logu ayni kayitlari gorur). Islemi yapan token'dan gelir. Log yazimi ana akisi kirmaz.
 */
class Denetim
{
    public static function yaz(string $islem, ?string $hedefTipi = null, ?int $hedefId = null, ?string $hedefEtiket = null, ?string $aciklama = null, array $meta = []): void
    {
        try {
            $yonetici = auth('sistemyonetim-api')->user();
            $istek = request();
            DB::table('sistemyonetim_audit_log')->insert([
                'user_id'      => $yonetici?->id,
                'user_name'    => $yonetici?->name,
                'user_rol'     => $yonetici?->rol()->value,
                'action'       => $islem,
                'target_type'  => $hedefTipi,
                'target_id'    => $hedefId,
                'target_label' => $hedefEtiket !== null ? mb_substr($hedefEtiket, 0, 200) : null,
                'aciklama'     => $aciklama,
                'meta'         => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip'           => $istek->ip(),
                'user_agent'   => mb_substr((string) $istek->header('User-Agent'), 0, 255),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Denetim::yaz basarisiz', ['islem' => $islem, 'hata' => $e->getMessage()]);
        }
    }
}
