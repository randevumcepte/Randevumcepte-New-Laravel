<?php

namespace App\Services\SistemYonetim\Izleme;

/** Denetim ve giris kayitlarinin API satir bicimi: dashboard, profil ve log ekranlari ayni alanlari gorur. */
final class IzlemeSunumu
{
    public const AKTIVITE_KOLONLARI = ['id', 'user_name', 'user_rol', 'action', 'target_type', 'target_id', 'target_label', 'aciklama', 'meta', 'ip', 'created_at'];

    public const GIRIS_KOLONLARI = ['id', 'email_attempt', 'basarili', 'hata', 'ip', 'user_agent', 'created_at'];

    public static function aktivite(object $k): array
    {
        return [
            'id'           => (int) $k->id,
            'kullanici'    => $k->user_name ?: 'Sistem',
            'rol'          => $k->user_rol,
            'islem'        => $k->action,
            'hedef_tipi'   => $k->target_type,
            'hedef_id'     => $k->target_id !== null ? (int) $k->target_id : null,
            'hedef_etiket' => $k->target_label,
            'aciklama'     => $k->aciklama,
            'meta'         => $k->meta ? json_decode($k->meta, true) : null,
            'ip'           => $k->ip,
            'zaman'        => IzlemeZamani::bicim($k->created_at),
            'zaman_once'   => IzlemeZamani::once($k->created_at),
        ];
    }

    public static function giris(object $k): array
    {
        return [
            'id'         => (int) $k->id,
            'email'      => $k->email_attempt,
            'basarili'   => (bool) $k->basarili,
            'hata'       => $k->hata,
            'ip'         => $k->ip,
            'tarayici'   => $k->user_agent,
            'zaman'      => IzlemeZamani::bicim($k->created_at),
            'zaman_once' => IzlemeZamani::once($k->created_at),
        ];
    }
}
