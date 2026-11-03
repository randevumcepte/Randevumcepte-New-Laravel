<?php

namespace App\Services\Aktivite;

use App\Domain\Aktivite\HareketKaynagi;
use App\Domain\Aktivite\HareketKatalogu;
use Illuminate\Support\Carbon;

/**
 * Log hareketi JSON sözleşmesi — web (/v2/log-hareketleri + gömülü hareket tabloları) ve Flutter
 * AYNI biçimi okur; istemci kategori/etiket/renk HESAPLAMAZ.
 *
 *   id, zaman (ISO-8601), tarih (d.m.Y), saat (H:i:s), once ("3 saat önce")
 *   kullanici {id, ad, rol, tip, bas_harf, renk}
 *   islem     {kod, etiket, kategori {kod, etiket, renk, zemin, ikon}}
 *   kaynak    {kod, etiket, ikon}
 *   hedef     {tip, tip_etiket, id, etiket}
 *   aciklama, detay (meta; 'kaynak' anahtarı ayrıldı — boşsa null), ip
 *
 * user_agent DÖNMEZ (veri minimizasyonu; eski ekran da göstermiyordu).
 */
final class HareketSunumu
{
    /** Rol etiketi → avatar rengi (eski ekranla aynı). */
    private const ROL_RENK = [
        'Hesap Sahibi' => '#7c3aed',
        'Yönetici'     => '#2563eb',
        'Personel'     => '#0d9488',
        'Satış Ortağı' => '#d97706',
    ];

    private const VARSAYILAN_RENK = '#64748b';

    public static function satir(object $r): array
    {
        $meta = self::metaCoz($r->meta);
        $kaynak = HareketKaynagi::cozumle($meta, $r->user_type);
        unset($meta['kaynak']);
        $islem = HareketKatalogu::islem((string) $r->action);
        $zaman = Carbon::parse($r->created_at);
        $ad = trim((string) $r->user_name) ?: 'Sistem';

        return [
            'id'        => (int) $r->id,
            'zaman'     => $zaman->toIso8601String(),
            'tarih'     => $zaman->format('d.m.Y'),
            'saat'      => $zaman->format('H:i:s'),
            'once'      => $zaman->copy()->locale('tr')->diffForHumans(),
            'kullanici' => [
                'id'       => $r->user_id !== null ? (int) $r->user_id : null,
                'ad'       => $ad,
                'rol'      => $r->user_rol ?: null,
                'tip'      => $r->user_type ?: null,
                'bas_harf' => mb_strtoupper(mb_substr($ad, 0, 1)),
                'renk'     => self::ROL_RENK[$r->user_rol] ?? self::VARSAYILAN_RENK,
            ],
            'islem'     => ['kod' => $islem['kod'], 'etiket' => $islem['etiket'], 'kategori' => $islem['kategori']->dizi()],
            'kaynak'    => $kaynak->dizi(),
            'hedef'     => [
                'tip'        => $r->target_type ?: null,
                'tip_etiket' => HareketKatalogu::hedefEtiketi($r->target_type),
                'id'         => $r->target_id !== null ? (int) $r->target_id : null,
                'etiket'     => $r->target_label ?: null,
            ],
            'aciklama'  => $r->aciklama ?: null,
            'detay'     => $meta ?: null,
            'ip'        => $r->ip ?: null,
        ];
    }

    /** meta TEXT (JSON) → dizi; bozuk / dizi olmayan JSON → []. */
    public static function metaCoz(?string $meta): array
    {
        if ($meta === null || $meta === '') {
            return [];
        }
        $d = json_decode($meta, true);

        return is_array($d) ? $d : [];
    }
}
