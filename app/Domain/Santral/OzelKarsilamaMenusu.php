<?php

namespace App\Domain\Santral;

/**
 * Santral ozel karsilama menusu (tus → kuyruk/dahili) — SAF mantik.
 *
 * Eski App\Salonlar::santralOzelMenuNormalize / santralOzelMenuMetni / SANTRAL_TUS_OKUNUS
 * ile BIREBIR (santral bu menuyu eski /api/v1/santralkarsilamametni ucundan okur; iki
 * tarafin ayni normalize + anons metnini uretmesi ZORUNLU — kural degisirse ikisi birlikte).
 *
 * Tus/tip listeleri de buradan API'ye verilir; web ve Flutter sabit liste tutmaz.
 */
final class OzelKarsilamaMenusu
{
    /** Tus → anonstaki okunusu ("... için biri tuşlayınız"). */
    public const TUS_OKUNUS = [
        '1' => 'biri', '2' => 'ikiyi', '3' => 'üçü', '4' => 'dördü', '5' => 'beşi',
        '6' => 'altıyı', '7' => 'yediyi', '8' => 'sekizi', '9' => 'dokuzu',
    ];

    /** Yonlendirme tipleri (UI secenek listesi + placeholder). */
    public const TIPLER = [
        ['kod' => 'kuyruk', 'ad' => 'Kuyruk', 'aciklama' => 'Birden çok dahilinin çaldığı santral kuyruğu', 'ornek' => 'Kuyruk no, örn: 10013'],
        ['kod' => 'dahili', 'ad' => 'Dahili', 'aciklama' => 'Tek bir personelin dahilisi',                 'ornek' => 'Dahili no, örn: 101'],
    ];

    public const ETIKET_MAKS = 60;

    /**
     * Ham secenek listesini temizler: gecerli tus (1-9, tekil), bos olmayan etiket,
     * tip (kuyruk|dahili), yalniz rakam hedef (2-10 hane). Tusa gore sirali doner.
     *
     * @return array<int, array{tus:string, etiket:string, tip:string, hedef:string}>
     */
    public static function normalize($ham): array
    {
        $temiz = [];
        foreach ((array) $ham as $satir) {
            $satir = (array) $satir;
            $tus = trim((string) ($satir['tus'] ?? ''));
            $etiket = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($satir['etiket'] ?? ''))));
            $tip = ($satir['tip'] ?? '') === 'dahili' ? 'dahili' : 'kuyruk';
            $hedef = (string) preg_replace('/\D/', '', (string) ($satir['hedef'] ?? ''));
            if (! isset(self::TUS_OKUNUS[$tus]) || isset($temiz[$tus]) || $etiket === '' || strlen($hedef) < 2 || strlen($hedef) > 10) {
                continue;
            }
            $temiz[$tus] = ['tus' => $tus, 'etiket' => mb_substr($etiket, 0, self::ETIKET_MAKS), 'tip' => $tip, 'hedef' => $hedef];
        }
        ksort($temiz);

        return array_values($temiz);
    }

    /** Santralin okuyacagi anons metni (secenek yoksa bos). */
    public static function metin(array $secenekler): string
    {
        if (empty($secenekler)) {
            return '';
        }
        $parcalar = array_map(fn ($s) => $s['etiket'].' için '.self::TUS_OKUNUS[$s['tus']], $secenekler);

        return implode(', ', $parcalar).' tuşlayınız. Menüyü tekrar dinlemek için sıfırı tuşlayınız. Operatöre bağlanmak için lütfen bekleyiniz.';
    }
}
