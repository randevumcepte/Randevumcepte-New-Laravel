<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Salon aktivite logu — eski App\SalonYonetim\Audit::logApi ile AYNI tablo
 * ('salon_aktivite_log') ve ayni kolon semantigi. Boylece v2 islemleri eski
 * panelin "Aktivite Gecmisi" ekraninda aynen gorunur.
 *
 * Fark: kimlik govdeden (olusturan/user_id) degil, dogrulanmis token'dan gelir.
 * meta.kaynak = 'v2'. Log yazimi ASLA ana akisi kirmaz.
 */
class AktiviteLog
{
    private const ROL_ETIKET = [
        1 => 'Hesap Sahibi',
        2 => 'Süpervizör',
        3 => 'Yönetici',
        4 => 'Yönetici',
        5 => 'Personel',
    ];

    public static function yaz(
        int $salonId,
        ?int $yetkiliId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $targetLabel = null,
        ?string $aciklama = null,
        array $meta = [],
    ): void {
        try {
            $roleId = $yetkiliId
                ? DB::table('model_has_roles')->where('model_id', $yetkiliId)->where('salon_id', $salonId)->value('role_id')
                : null;

            $meta['kaynak'] = $meta['kaynak'] ?? 'v2';
            $req = request();

            DB::table('salon_aktivite_log')->insert([
                'salon_id'     => $salonId,
                'user_id'      => $yetkiliId,
                'user_type'    => $yetkiliId ? ((int) $roleId === 5 ? 'personel' : 'yetkili') : 'sistem',
                'user_name'    => $yetkiliId ? DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->value('name') : 'Sistem',
                'user_rol'     => $yetkiliId ? (self::ROL_ETIKET[$roleId] ?? ($roleId ? 'Rol#'.$roleId : 'Yetkili')) : 'Sistem',
                'action'       => $action,
                'target_type'  => $targetType,
                'target_id'    => $targetId,
                'target_label' => $targetLabel !== null ? mb_substr($targetLabel, 0, 200) : null,
                'aciklama'     => $aciklama,
                'meta'         => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip'           => $req?->ip(),
                'user_agent'   => mb_substr((string) $req?->header('User-Agent'), 0, 255),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AktiviteLog::yaz basarisiz', ['action' => $action, 'salon_id' => $salonId, 'hata' => $e->getMessage()]);
        }
    }
}
