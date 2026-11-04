<?php

namespace App\Domain\Ders;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * "Programi Yayinla" tarih hesabi — SAF (DB yok). Haftalik sablonun hangi tarihlerde
 * oturum uretecegini cozer. Eski web + mobil ders_programi_yayinla ile ayni kural:
 *
 *   - Stüdyo: en fazla 52 hafta + opsiyonel bitis tarihi. Diger: en fazla 12 hafta, bitis yok.
 *   - Baslangictan once ve bitisten sonraki gunler uretilmez.
 *   - hafta_gunu: 1=Pazartesi … 7=Pazar.
 */
final class YayinAraligi
{
    public const VARSAYILAN_HAFTA = 4;

    public const STUDYO_MAKS_HAFTA = 52;

    public const NORMAL_MAKS_HAFTA = 12;

    /** Bitis tarihli yayinda araligi kapsayan hafta sayisinin tavani. */
    private const BITISLI_MAKS_HAFTA = 54;

    private function __construct(
        private readonly DateTimeImmutable $baslangic,
        private readonly ?DateTimeImmutable $bitis,
        public readonly int $hafta,
    ) {}

    /** @throws InvalidArgumentException bitis baslangictan onceyse */
    public static function olustur(string $baslangic, ?string $bitis, ?int $hafta, bool $studyo): self
    {
        $bas = new DateTimeImmutable(substr($baslangic, 0, 10));
        $son = ($studyo && $bitis) ? new DateTimeImmutable(substr($bitis, 0, 10)) : null;

        if ($son) {
            if ($son < $bas) {
                throw new InvalidArgumentException('Bitiş tarihi başlangıçtan önce olamaz.');
            }

            return new self($bas, $son, min(self::BITISLI_MAKS_HAFTA, (int) floor($bas->diff($son)->days / 7) + 2));
        }

        return new self($bas, null, max(1, min(self::maksHafta($studyo), $hafta ?: self::VARSAYILAN_HAFTA)));
    }

    public function baslangicTarihi(): string
    {
        return $this->baslangic->format('Y-m-d');
    }

    public static function maksHafta(bool $studyo): int
    {
        return $studyo ? self::STUDYO_MAKS_HAFTA : self::NORMAL_MAKS_HAFTA;
    }

    /**
     * Bu araliktaki, verilen hafta gunune denk gelen tarihler (Y-m-d, artan).
     *
     * @return string[]
     */
    public function tarihler(int $haftaGunu): array
    {
        $sonuc = [];
        for ($h = 0; $h < $this->hafta; $h++) {
            $ref = $this->baslangic->modify('+'.($h * 7).' days');
            $tarih = $ref->modify(($haftaGunu - (int) $ref->format('N')).' days');
            if ($tarih < $this->baslangic || ($this->bitis && $tarih > $this->bitis)) {
                continue;
            }
            $sonuc[] = $tarih->format('Y-m-d');
        }

        return $sonuc;
    }
}
