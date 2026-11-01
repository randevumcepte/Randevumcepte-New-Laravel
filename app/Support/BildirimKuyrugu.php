<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * v2 → eski monolit bildirim kuyrugu (outbox) — 'v2_bildirim_kuyrugu'.
 *
 * v2 yan etki URETMEZ; buraya yazar, eski monolitin `v2:bildirim-isle` komutu
 * MEVCUT kodla gonderir (SMS/WA, FCM, uygulama-ici bildirim, Google Takvim).
 * Cagiran DB::transaction icindeyse kayit onunla birlikte commit/rollback olur:
 * durum yazildiysa bildirim kaybolmaz, yazilmadiysa hayalet bildirim gitmez.
 *
 * Turler: randevu_iptal, randevu_onay, dogrulama_kodu, kvkk_kodu, seans_kullanim,
 *         randevu_tasindi, google_takvim (payload: sync[] / sil[] rh id),
 *         personel_sifre (payload: personel_id, yeni_hesap), yetki_degisti,
 *         personel_pasif (payload: personel_id; randevu_id null).
 */
class BildirimKuyrugu
{
    public static function yaz(int $salonId, string $tur, ?int $randevuId, ?int $yetkiliId, array $payload = []): void
    {
        DB::table('v2_bildirim_kuyrugu')->insert([
            'salon_id'   => $salonId,
            'tur'        => $tur,
            'randevu_id' => $randevuId,
            'yetkili_id' => $yetkiliId,
            'payload'    => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'durum'      => 0,
            'deneme'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Google Takvim senkronu (eski RandevuHizmetler saved/deleting model event'i
     * karsiligi — v2 DB'ye dogrudan yazdigi icin event tetiklenmez).
     *
     * @param  int[]  $sync  kaydedilen/guncellenen randevu_hizmetler id
     * @param  int[]  $sil   silinen randevu_hizmetler id
     */
    public static function googleTakvim(int $salonId, ?int $randevuId, array $sync = [], array $sil = []): void
    {
        if (! $sync && ! $sil) {
            return;
        }
        self::yaz($salonId, 'google_takvim', $randevuId, null, array_filter([
            'sync' => array_values(array_map('intval', $sync)),
            'sil'  => array_values(array_map('intval', $sil)),
        ]));
    }
}
