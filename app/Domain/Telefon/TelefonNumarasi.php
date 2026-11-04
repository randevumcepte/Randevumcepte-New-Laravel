<?php

namespace App\Domain\Telefon;

/**
 * Telefon kanonik (saklama) bicimi + eski kayit varyantlari — TUM telefon alanlari
 * (musteri, isletme, tedarikci, sozlesme) icin TEK kural. Eski
 * StoreAdminController::telefonKanonik / telefonAramaVaryantlari'nin devami.
 *
 * TURKIYE : +90 / 90 / 0 soyulur → 10 haneli "5XXXXXXXXX".
 * YABANCI : "+KOD" + ulusal numara (bastaki trunk 0 atilir) → "+491761234567".
 *           "+" / "00" onekiyle gelen numara uluslararasidir. Eski kod "+"yi de atiyordu
 *           ("4917...") ve ekranda TR sanilabiliyordu; onekesiz eski kayit oldugu gibi kalir.
 * Web telefon-ulke.js ve Flutter ayni degeri gonderir; sunucu yine de bu kurali uygular.
 *
 * Duplicate-musteri kontrolu bu bicimle yapilir (personel telefonundaki eski normalize
 * kuralindan farklidir; bkz. App\Domain\Personel\Telefon).
 */
final class TelefonNumarasi
{
    /** "0532 123 45 67" / "+90 532..." / "0090532..." → "5321234567"; "+49 0176..." → "+49176..." */
    public static function kanonik(?string $ham): string
    {
        if ($ham === null) {
            return '';
        }
        // Kopyala-yapistir kaynakli gorunmez unicode (NBSP, ZWSP, LRM/RLM, BOM)
        $s = trim((string) preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{FEFF}]/u', '', $ham));
        $d = preg_replace('/\D/', '', $s);
        if ($d === '') {
            return '';
        }
        $uluslararasi = str_starts_with($s, '+');
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
            $uluslararasi = true;
        }

        if ($uluslararasi) {
            return self::uluslararasi($d);
        }
        if (strlen($d) === 12 && str_starts_with($d, '90')) {
            return substr($d, 2);
        }
        if (strlen($d) === 11 && $d[0] === '0') {
            return substr($d, 1);
        }

        return $d; // 10 haneli TR cep zaten kanonik; oneksiz yabanci (eski kayit) oldugu gibi
    }

    /** Kanonik numara gecerli bir hesap telefonu mu: TR cep (5XXXXXXXXX) ya da "+KOD..." uluslararasi. */
    public static function gecerliMi(string $kanonik): bool
    {
        return (bool) preg_match('/^(5\d{9}|\+\d{7,15})$/', $kanonik);
    }

    /**
     * Kanonik numaranin DB'de gecmisten kalma farkli bicimleri (dedup aramasi icin).
     *
     * @return string[]
     */
    public static function aramaVaryantlari(string $kanonik): array
    {
        if ($kanonik === '') {
            return [];
        }
        $d = ltrim($kanonik, '+');
        $v = strlen($kanonik) === 10 && $kanonik[0] === '5'
            ? [$kanonik, '0'.$kanonik, '90'.$kanonik, '+90'.$kanonik, '0090'.$kanonik]
            : [$kanonik, $d, '+'.$d, '00'.$d]; // yabanci: eski kayitlar "+"siz saklanmis olabilir

        return array_values(array_unique($v));
    }

    /** Uluslararasi rakamlar (oneksiz) → TR ise 10 hane, degilse "+KOD" + trunk 0'siz numara. */
    private static function uluslararasi(string $d): string
    {
        $c = TelefonUlkeleri::koddanCoz($d);
        if (! $c) {
            return '+'.$d;
        }
        $numara = $c['sifir_kalir'] ? $c['numara'] : ltrim($c['numara'], '0');

        return $c['kod'] === '90' ? $numara : '+'.$c['kod'].$numara;
    }
}
