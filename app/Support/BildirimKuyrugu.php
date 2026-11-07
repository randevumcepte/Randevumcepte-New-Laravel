<?php

namespace App\Support;

use App\Domain\Bildirim\BildirimKuyrukDurumu;
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
 *         kara_liste_bildirim (ESKİ, artık yazılmaz; kuyruktaki eski satırlar için monolitte duruyor),
 *         sms (payload: mesajlar[{to,message}], dogrulama; randevu_id null — kasa bildirimleri / onay kodlari),
 *         ongorusme_kayit (randevu_id = on gorusme randevusu; payload: on_gorusme_id, guncelleme —
 *           musteri SMS + personel SMS/bildirim/push, ayar_id=12).
 *         anket_link (payload: gonderim_id; randevu_id null — v2'de oluşturulan anket gönderiminin linkini WA-first/SMS yollar),
 *         anket_gonder (ESKİ, artık yazılmaz; kuyruktaki eski satırlar için monolitte duruyor),
 *         whatsapp_mesaj (payload: user_id, mesaj, gonderim_tipi — Çağrı Merkezi ve müşteri kartı manuel WhatsApp),
 *         cark_duyuru (payload: baslik, mesaj; randevu_id null — Çarkıfelek "müşterilere duyur" push'u),
 *         sistem_bildirim (payload: mesaj; randevu_id null — sistem sahibine WA+SMS, ör. WhatsApp kontör talebi),
 *         kampanya_olustur (payload: kampanya_id; randevu_id null — Reklam Yönetimi yeni kampanya → personel push'u),
 *         ders_bildirim (payload: user_id, mesaj, gonderim_tipi; randevu_id null — grup dersi kayit/bekleme/terfi/iptal/kalan uyarisi),
 *         sifre_degisti (payload: yetkili_id, haric_token; randevu_id null — kullanici kendi sifresini degistirdi: diger oturumlar kapatilir),
 *         sms_toplu (payload: telefonlar[], mesaj, tur, baslik; randevu_id null — SMS Yönetimi toplu/filtreli gönderim).
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
            'durum'      => BildirimKuyrukDurumu::yeniSatir(),
            'deneme'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Müşteriye manuel WhatsApp mesajı (Çağrı Merkezi, müşteri kartı). */
    public static function whatsappMesaji(int $salonId, ?int $yetkiliId, int $userId, string $mesaj): void
    {
        self::yaz($salonId, 'whatsapp_mesaj', null, $yetkiliId, ['user_id' => $userId, 'mesaj' => $mesaj, 'gonderim_tipi' => 'manuel_musteri']);
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
