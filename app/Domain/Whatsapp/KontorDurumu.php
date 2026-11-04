<?php

namespace App\Domain\Whatsapp;

/**
 * Kontör bandı + uyarı popup'ının içeriği (saf) — eski whatsapp.blade'deki Blade koşulları.
 * Metin ve eşikler sunucuda: web ve Flutter aynı uyarıyı aynı kuralla gösterir.
 *
 *   ücretsiz dönem : bitiş tarihine kadar kontör düşmez (bakiye varsa bilgi olarak gösterilir)
 *   kontörlü dönem : <=1000 düşük (günde 1 uyarı) · <=500 kritik (günde 3) · <=0 bitti (her açılışta)
 */
final class KontorDurumu
{
    private const ESIK_DUSUK = 1000;

    private const ESIK_KRITIK = 500;

    /** Bitti seviyesinde "her açılışta" — istemci günlük sayacı bu değere asla ulaşmaz. */
    private const LIMIT_SINIRSIZ = 99;

    private const AYLAR = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public static function olustur(WhatsappDurumu $d, ?string $denemeBitis): array
    {
        $bakiye = $d->kontor;

        return ($d->kontorluDonem ? self::kontorlu($bakiye) : self::ucretsiz($bakiye, $denemeBitis))
            + ['bakiye' => $bakiye, 'kontorlu_donem' => $d->kontorluDonem];
    }

    private static function ucretsiz(int $bakiye, ?string $denemeBitis): array
    {
        $bitis = self::denemeBitisi($denemeBitis);
        $baslangic = $bitis->modify('+1 day');
        $bitisMetin = self::tarih($bitis);
        $baslangicMetin = self::tarih($baslangic);

        return [
            'seviye'  => 'ucretsiz',
            'renk'    => 'yesil',
            'ikon'    => '🎉',
            'baslik'  => "{$bitisMetin} tarihine kadar WhatsApp tamamen ÜCRETSİZ",
            'metin'   => "{$baslangicMetin} tarihinden itibaren kontörlü sisteme geçilecek — 1 mesaj = 1 kontör. Şimdiden paketleri inceleyip hazırlanabilirsiniz.",
            'not'     => $bakiye > 0 ? 'Yüklü kontör bakiyeniz: '.self::sayi($bakiye)." — {$baslangicMetin} tarihinde kullanılmaya başlayacak (şu an düşmüyor)." : null,
            'rozet'   => $bakiye > 0 ? self::sayi($bakiye).' KONTÖR' : 'ŞU AN ÜCRETSİZ',
            'uyari'   => null,
        ];
    }

    private static function kontorlu(int $bakiye): array
    {
        [$seviye, $renk, $ikon, $metin, $uyari] = match (true) {
            $bakiye <= 0 => ['bitti', 'kirmizi', '⛔',
                'Kontörünüz bitti — hatırlatma ve mesajlar müşteri/danışanlarınıza gitmiyor. Hemen kontör alın.',
                ['WhatsApp Kontörünüz Bitti!', 'Hatırlatma ve mesajlar müşteri/danışanlarınıza GİTMİYOR. Hemen kontör satın alın, yoksa randevu hatırlatmaları çalışmaz.', self::LIMIT_SINIRSIZ]],
            $bakiye <= self::ESIK_KRITIK => ['kritik', 'kirmizi', '⚠️',
                'Kontörünüz azaldı, çok yakında bitecek. Mesajlarınız durmadan kontör alın.',
                ['Kontörünüz Kritik! ('.self::sayi($bakiye).' kaldı)', 'Çok yakında bitecek ve mesajlarınız duracak. Şimdi kontör yükleyin.', 3]],
            $bakiye <= self::ESIK_DUSUK => ['dusuk', 'turuncu', '💰',
                '1 mesaj = 1 kontör. Bittiğinde WhatsApp mesajları durur.',
                ['Kontörünüz Azalıyor ('.self::sayi($bakiye).')', 'Kesinti yaşamamak için kontör almayı düşünün.', 1]],
            default => ['normal', 'yesil', '💰', '1 mesaj = 1 kontör. Bittiğinde WhatsApp mesajları durur.', null],
        };

        return [
            'seviye' => $seviye,
            'renk'   => $renk,
            'ikon'   => $ikon,
            'baslik' => 'Kontör bakiyeniz: '.self::sayi($bakiye),
            'metin'  => $metin,
            'not'    => null,
            'rozet'  => self::sayi($bakiye).' KONTÖR',
            'uyari'  => $uyari ? ['baslik' => $uyari[0], 'metin' => $uyari[1], 'gunluk_limit' => $uyari[2]] : null,
        ];
    }

    /** Salon-özel bitiş; yok/bozuksa global kontör başlangıcının bir gün öncesi (2026-08-31). */
    private static function denemeBitisi(?string $denemeBitis): \DateTimeImmutable
    {
        $bitis = substr((string) $denemeBitis, 0, 10);

        return $bitis !== '' && $bitis !== '0000-00-00'
            ? new \DateTimeImmutable($bitis)
            : (new \DateTimeImmutable(WhatsappDurumu::KONTOR_BASLANGIC))->modify('-1 day');
    }

    private static function tarih(\DateTimeImmutable $t): string
    {
        return $t->format('d').' '.self::AYLAR[(int) $t->format('n')].' '.$t->format('Y');
    }

    private static function sayi(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
