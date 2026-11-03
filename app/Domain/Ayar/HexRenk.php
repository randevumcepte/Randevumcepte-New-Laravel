<?php

namespace App\Domain\Ayar;

/**
 * #RRGGBB renk degerleri — SAF mantik (takvim tek renk, yazi rengi, renk sirasi).
 *
 * Eski StoreAdminController@randevuayarguncelle + ayarlar.blade @php blogu ile BIREBIR:
 *  - gecerli hex BUYUK harfe cevrilir,
 *  - yazi rengi verilmemisse zemin parlakligina gore otomatik (#000000 / #FFFFFF),
 *  - renk sirasi {bg,text} ciftleri; eski bicimler (hex dizisi / virgullu metin) da okunur.
 */
final class HexRenk
{
    public const TEK_RENK_VARSAYILAN = '#5C008E';
    public const YAZI_RENGI_VARSAYILAN = '#FFFFFF';

    /** Gecerliyse '#RRGGBB' (buyuk harf), degilse null. */
    public static function normalize($deger): ?string
    {
        $v = trim((string) $deger);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : null;
    }

    /** Zemin parlakligina gore okunur yazi rengi (eski $_oto ile ayni esik: 0.6). */
    public static function otomatikYazi(string $bg): string
    {
        $r = hexdec(substr($bg, 1, 2));
        $g = hexdec(substr($bg, 3, 2));
        $b = hexdec(substr($bg, 5, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.6 ? '#000000' : '#FFFFFF';
    }

    /**
     * Ham renk sirasi (JSON metni, dizi ya da virgullu hex) → [{bg,text}] (gecersizler elenir).
     *
     * @return list<array{bg:string,text:string}>
     */
    public static function siraCoz($ham): array
    {
        if (is_string($ham)) {
            $ham = trim($ham);
            if ($ham === '') {
                return [];
            }
            $cozulmus = json_decode($ham, true);
            $ham = is_array($cozulmus) ? $cozulmus : explode(',', $ham);
        }

        $sira = [];
        foreach ((array) $ham as $e) {
            $bg = self::normalize(is_array($e) ? ($e['bg'] ?? null) : $e);
            if ($bg === null) {
                continue;
            }
            $text = is_array($e) ? self::normalize($e['text'] ?? null) : null;
            $sira[] = ['bg' => $bg, 'text' => $text ?? self::otomatikYazi($bg)];
        }

        return $sira;
    }

    /**
     * Ekranda gosterilecek tam sira: kayitli ciftler + palette olup sirada olmayanlar
     * (eski blade'deki gibi). Kayit yoksa palet sirasi.
     *
     * @param  list<string>  $palet
     * @return list<array{bg:string,text:string}>
     */
    public static function siraTamamla(array $kayitli, array $palet): array
    {
        $mevcut = array_column($kayitli, 'bg');
        foreach ($palet as $h) {
            if (! in_array($h, $mevcut, true)) {
                $kayitli[] = ['bg' => $h, 'text' => self::otomatikYazi($h)];
            }
        }

        return $kayitli;
    }
}
