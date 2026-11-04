<?php

namespace App\Domain\Anket;

use App\Domain\Form\SablonSorulari;

/**
 * Anket soru listesi (anket_sablonlari.sorular_json) okuma + doğrulama + normalizasyon — SAF, TEK KAYNAK.
 *
 * Kanonik öğe: {tip, soru, baslik, zorunlu, secenekler?}.
 * `soru` + `baslik` ikisi de yazılır: müşteri anket sayfası ve web `soru`, Flutter editörü `baslik`
 * okuyordu → Flutter'da oluşturulan şablonların soruları müşteriye BOŞ görünüyordu. Eski kayıt ikisinden
 * hangisini taşırsa taşısın burada eşitlenir.
 */
final class AnketSorulari
{
    public const MAKS_SORU = 50;
    public const MAKS_UZUNLUK = 1000;
    public const MAKS_SECENEK = 30;

    /**
     * Kaydetmeden önce: tip geçerli, metin dolu, seçenekli tipte seçenek var, en az bir cevaplı soru.
     *
     * @param  mixed  $girdi  dizi ya da JSON metni (Flutter v1 sorular_json string gönderir)
     * @return list<array<string,mixed>>
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(mixed $girdi): array
    {
        if (is_string($girdi)) {
            $girdi = json_decode($girdi, true);
        }
        if (! is_array($girdi)) {
            throw new \InvalidArgumentException('Soru listesi geçersiz.');
        }

        $sorular = [];
        foreach (array_values($girdi) as $i => $ham) {
            $tip = is_array($ham) ? SoruTipi::dbden($ham['tip'] ?? null) : null;
            if (! $tip) {
                throw new \InvalidArgumentException(($i + 1).'. soru tipi geçersiz.');
            }
            $soru = self::oge($ham, $tip);
            if ($soru['soru'] === '') {
                throw new \InvalidArgumentException(($i + 1).'. soru metni boş.');
            }
            if ($tip->secenekli() && ! $soru['secenekler']) {
                throw new \InvalidArgumentException(($i + 1).'. soruya en az bir seçenek ekleyin.');
            }
            $sorular[] = $soru;
        }

        if (count($sorular) > self::MAKS_SORU) {
            throw new \InvalidArgumentException('En fazla '.self::MAKS_SORU.' soru eklenebilir.');
        }
        if (! array_filter($sorular, fn ($s) => SoruTipi::from($s['tip'])->cevapli())) {
            throw new \InvalidArgumentException('En az bir cevap verilebilir soru ekleyin.');
        }

        return $sorular;
    }

    /**
     * Kayıtlı JSON'u göstermek için (bozuk / bilinmeyen öğeyi sessizce atlar, hata atmaz).
     * İndeks korunur: müşteri cevapları soruya dizi sırasıyla (`indeks`) bağlıdır.
     *
     * @return list<array<string,mixed>>
     */
    public static function coz(?string $json): array
    {
        $sonuc = [];
        foreach (SablonSorulari::coz($json) as $ham) {
            $tip = SoruTipi::dbden($ham['tip'] ?? null);
            $sonuc[] = $tip ? self::oge($ham, $tip) : ['tip' => (string) ($ham['tip'] ?? ''), 'soru' => '', 'baslik' => '', 'zorunlu' => false];
        }

        return $sonuc;
    }

    /** Yeni şablon formunun ve "hazır şablon"un soruları (eski yeniSablonAc ile aynı). */
    public static function hazirSablon(): array
    {
        return array_map(fn ($s) => self::oge($s, SoruTipi::from($s['tip'])), [
            ['tip' => 'nps', 'soru' => 'Bizi bir arkadaşınıza tavsiye etme olasılığınız 0-10 arasında nedir?', 'zorunlu' => true],
            ['tip' => 'csat_yildiz', 'soru' => 'Genel olarak hizmet kalitemizi nasıl değerlendirirsiniz?', 'zorunlu' => true],
            ['tip' => 'csat_yildiz', 'soru' => 'Personelimizin ilgisi ve profesyonelliği nasıldı?', 'zorunlu' => false],
            ['tip' => 'uzun_metin', 'soru' => 'Eklemek istediğiniz başka bir görüş var mı?', 'zorunlu' => false],
        ]);
    }

    /** @return array<string,mixed> */
    private static function oge(array $ham, SoruTipi $tip): array
    {
        $metin = mb_substr(trim((string) (($ham['soru'] ?? '') !== '' ? $ham['soru'] : ($ham['baslik'] ?? ''))), 0, self::MAKS_UZUNLUK);
        $oge = [
            'tip'     => $tip->value,
            'soru'    => $metin,
            'baslik'  => $metin,
            'zorunlu' => $tip->cevapli() && ! empty($ham['zorunlu']),
        ];
        if ($tip->secenekli()) {
            $oge['secenekler'] = array_slice(array_values(array_filter(
                array_map(fn ($s) => mb_substr(trim((string) $s), 0, self::MAKS_UZUNLUK), (array) ($ham['secenekler'] ?? [])),
                fn ($s) => $s !== '',
            )), 0, self::MAKS_SECENEK);
        }

        return $oge;
    }
}
