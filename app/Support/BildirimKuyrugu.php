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
 *         personel_pasif (payload: personel_id; randevu_id null),
 *         musteri_kayit (payload: user_id, yeni_hesap, cinsiyet_tahmin),
 *         kara_liste_bildirim (payload: user_id),
 *         sms (payload: mesajlar[{to,message}], dogrulama; randevu_id null — kasa bildirimleri / onay kodlari),
 *         ongorusme_kayit (randevu_id = on gorusme randevusu; payload: on_gorusme_id, guncelleme —
 *           musteri SMS + personel SMS/bildirim/push, ayar_id=12).
 *         anket_gonder (payload: user_id, personel_id, kanal; randevu_id null — Çağrı Merkezi memnuniyet anketi).
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

    /**
     * Duz SMS (eski sms_gonder_bildirimli). dogrulama=true: onay kodu — SMS iletim raporuna
     * yazilmaz (eskiyle ayni). Bos/numarasiz mesajlar elenir; hic kalmazsa kayit acilmaz.
     *
     * @param  array<int, array{to:?string, message:string}>  $mesajlar
     */
    public static function sms(int $salonId, ?int $yetkiliId, array $mesajlar, bool $dogrulama = false): int
    {
        $mesajlar = array_values(array_filter($mesajlar, fn ($m) => trim((string) ($m['to'] ?? '')) !== '' && trim((string) ($m['message'] ?? '')) !== ''));
        if ($mesajlar) {
            self::yaz($salonId, 'sms', null, $yetkiliId, ['mesajlar' => $mesajlar, 'dogrulama' => $dogrulama]);
        }

        return count($mesajlar);
    }
}
