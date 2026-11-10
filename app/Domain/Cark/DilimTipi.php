<?php

namespace App\Domain\Cark;

/**
 * Çark DİLİMİ / kazanılan ödülün tipi — saf sözlük (DB'siz). Eski web (StoreAdminController::
 * carkdilimekle) ve mobil (ApiController::carkDilimKaydet) aynı kuralları ayrı ayrı kodluyordu;
 * burada TEK yerde. Web + Flutter seçenekleri GET /cark → sozluk.tipler'den okur.
 *
 *   değerli  : puan miktarı ya da indirim değeri taşır (tekrar_dene / bos taşımaz)
 *   kupon    : kazanılınca müşteriye kupon kodu üretilir (indirim tipleri)
 *   birim    : 'puan' | indirimde salon sahibinin seçimi (yuzde → % · tutar → ₺)
 */
final class DilimTipi
{
    public const PUAN = 'puan';
    public const HIZMET = 'hizmet_indirimi';
    public const URUN = 'urun_indirimi';
    public const PAKET = 'paket_indirimi';
    public const TEKRAR = 'tekrar_dene';
    public const BOS = 'bos';

    public const YUZDE = 'yuzde';
    public const TUTAR = 'tutar';

    /** kod => [etiket, kısa ad (çark iç yazısı), emoji] — sıra editördeki seçenek sırasıdır. */
    private const TANIM = [
        self::PUAN   => ['Puan', 'Puan', '💰'],
        self::HIZMET => ['Hizmet', 'Hizmet İnd.', '✂️'],
        self::URUN   => ['Ürün', 'Ürün İnd.', '📦'],
        self::PAKET  => ['Paket', 'Paket İnd.', '🎁'],
        self::TEKRAR => ['Tekrar', 'Tekrar Dene', '🔄'],
        self::BOS    => ['Boş', 'Boş', '—'],
    ];

    /** @return string[] */
    public static function kodlar(): array
    {
        return array_keys(self::TANIM);
    }

    public static function gecerli(?string $tip): string
    {
        return isset(self::TANIM[(string) $tip]) ? (string) $tip : self::BOS;
    }

    public static function indirimMi(string $tip): bool
    {
        return in_array($tip, [self::HIZMET, self::URUN, self::PAKET], true);
    }

    public static function degerliMi(string $tip): bool
    {
        return $tip === self::PUAN || self::indirimMi($tip);
    }

    /** İndirim biriminin normal hali — yalnız indirim tiplerinde 'tutar' olabilir. */
    public static function birim(string $tip, ?string $indirimTipi): string
    {
        return self::indirimMi($tip) && $indirimTipi === self::TUTAR ? self::TUTAR : self::YUZDE;
    }

    /** Değerin okunur hali: "100" (puan) · "%20" · "150₺". Değersizse ''. */
    public static function degerMetni(string $tip, ?float $deger, ?string $indirimTipi): string
    {
        if (! self::degerliMi($tip) || $deger === null) {
            return '';
        }
        $sayi = rtrim(rtrim(number_format($deger, 2, '.', ''), '0'), '.');
        if (! self::indirimMi($tip)) {
            return $sayi;
        }

        return self::birim($tip, $indirimTipi) === self::TUTAR ? $sayi.'₺' : '%'.$sayi;
    }

    /** Ödülün tam açıklaması ("100 Puan", "%20 Hizmet İnd.", "Tekrar Dene") — log/kazanan listeleri. */
    public static function odulMetni(string $tip, ?float $deger, ?string $indirimTipi): string
    {
        $tip = self::gecerli($tip);
        $deger = self::degerMetni($tip, $deger, $indirimTipi);

        return trim($deger.' '.self::TANIM[$tip][1]);
    }

    /**
     * Çark üstündeki yazılar (vitrin + uygulama çizimi; eski salon sitesi betiğindeki buildLabel / buildFullLabel):
     *   etiket      : dilimin iç yazısı — değerli dilimde tipin kısa adı (değer ayrı yazılır), değeri girilmemişse dilim adı
     *   deger_metni : "%20" · "150₺" · "100"; değersizse '' (çizim tek satır olur)
     *   tam_etiket  : sonuç ekranı ("%20 Hizmet İnd.", "100 Puan", "Tekrar Dene")
     *
     * @return array{etiket:string, deger_metni:string, tam_etiket:string}
     */
    public static function carkYazilari(?string $tip, ?float $deger, ?string $ad, ?string $indirimTipi): array
    {
        $tip = self::gecerli($tip);
        $kisa = self::TANIM[$tip][1];
        $etiket = self::degerliMi($tip) && $deger === null ? (trim((string) $ad) ?: $kisa) : $kisa;
        $degerMetni = self::degerMetni($tip, $deger, $indirimTipi);

        return ['etiket' => $etiket, 'deger_metni' => $degerMetni, 'tam_etiket' => trim($degerMetni.' '.$etiket)];
    }

    /** Editör / Flutter seçenekleri. */
    public static function sozluk(): array
    {
        return array_map(fn (string $kod) => [
            'kod'    => $kod,
            'ad'     => self::TANIM[$kod][0],
            'kisa'   => self::TANIM[$kod][1],
            'emoji'  => self::TANIM[$kod][2],
            'degerli' => self::degerliMi($kod),
            'kupon'  => self::indirimMi($kod),
        ], self::kodlar());
    }
}
