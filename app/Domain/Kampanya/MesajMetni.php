<?php

namespace App\Domain\Kampanya;

/**
 * Kampanya mesaj metni — yer tutucu çözümü (saf; eski kampanyaIceriginiGoruntule +
 * KampanyaYonetimi::kisisellestir + KampanyaAramaYap::katilimciParametresi kuralları TEK yerde).
 *
 * İki aşama:
 *   KAMPANYA aşaması (kayıtta çözülür)  : {işletmeden} {indirim} {indirim_veya_x_al_y_ode} {ürün_hizmet_paket}
 *   KİŞİ aşaması (gönderimde, eski cron) : {müşteri} {gün}   → kayıtta YER TUTUCU olarak kalır
 *
 * Arama kanalında santral cron'u metnin başına kendi selamını ekler ("Merhaba X. Sizi Y
 * arıyorum. Umarım …"); bu yüzden şablondan gelen metnin İLK CÜMLESİ (şablonların kendi
 * selamı) kayıtta atılır (eski davranış) ve önizlemede cron selamı gösterilir.
 */
final class MesajMetni
{
    /** Sihirbaz yardım listesi (web + Flutter). */
    public const YER_TUTUCULAR = [
        ['kod' => '{müşteri}', 'aciklama' => 'Müşterinin adı soyadı (kişiye özel)'],
        ['kod' => '{gün}', 'aciklama' => 'Müşterinin son gelişinden bu yana geçen gün (kişiye özel)'],
        ['kod' => '{işletmeden}', 'aciklama' => 'İşletmenizin adı'],
        ['kod' => '{indirim}', 'aciklama' => 'İndirim oranı (ör. 10) ya da "2 Al 1 Öde"'],
        ['kod' => '{ürün_hizmet_paket}', 'aciklama' => 'Seçtiğiniz hizmet / ürün / paket adı'],
    ];

    /** Kişi aşaması yer tutucuları (kayıtta korunur). */
    public const KISIYE_OZEL = ['{müşteri}', '{gün}'];

    /**
     * Kampanya aşaması: salon/kampanya bazlı yer tutucular. Değeri bilinmeyen (null) yer
     * tutucu metinde kalır (eski: hizmet seçilmezse {ürün_hizmet_paket} kalıyordu).
     */
    public static function kampanyaAsamasi(string $metin, string $isletme, ?Indirim $indirim, ?string $kalemAdi): string
    {
        return strtr($metin, array_filter([
            '{işletmeden}'              => $isletme,
            '{indirim_veya_x_al_y_ode}' => $indirim?->uzunYerTutucu(),
            '{indirim}'                 => $indirim?->yerTutucu() ?? '',
            '{ürün_hizmet_paket}'       => $kalemAdi ?: null,
        ], fn ($v) => $v !== null));
    }

    /** Kişi aşaması (önizleme + gönderim). */
    public static function kisiAsamasi(string $metin, string $musteriAdi, int $yoklukGun): string
    {
        return strtr($metin, ['{müşteri}' => $musteriAdi, '{gün}' => (string) $yoklukGun]);
    }

    /** Arama kanalında şablon selamını at (eski preg_replace birebir: ilk cümle). */
    public static function ilkCumleyiAt(string $metin): string
    {
        return trim((string) preg_replace('/^([^.!?]*[.!?]\s*){1}/u', '', $metin));
    }

    /** Arama cron'unun eklediği selam (eski KampanyaAramaYap::katilimciParametresi BİREBİR). */
    public static function aramaSelami(string $musteriAdi, string $isletme): string
    {
        return 'Merhaba '.$musteriAdi.'. Sizi '.$isletme.' arıyorum. Umarım gününüz sağlıklı geçiyordur. ';
    }

    /**
     * Kişinin göreceği / duyacağı son metin (önizleme). Arama kanalında cron selamı eklenir.
     */
    public static function onizleme(string $kayitMetni, Kanal $kanal, string $musteriAdi, int $yoklukGun, string $isletme): string
    {
        $govde = self::kisiAsamasi($kayitMetni, $musteriAdi, $yoklukGun);

        return trim($kanal === Kanal::Arama ? self::aramaSelami($musteriAdi, $isletme).$govde : $govde);
    }
}
