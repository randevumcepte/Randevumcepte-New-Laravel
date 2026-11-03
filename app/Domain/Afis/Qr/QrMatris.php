<?php

namespace App\Domain\Afis\Qr;

/**
 * Kodlanmış QR sembolü (değişmez değer nesnesi). Sessiz alan (quiet zone) İÇERMEZ —
 * çizen taraf kendi kenar boşluğunu ekler.
 *
 * Koordinat düzeni: koyuMu($x, $y) → $x sütun, $y satır (0,0 sol üst).
 */
final class QrMatris
{
    /** @param  list<list<int>>  $satirlar  [$y][$x] = 0|1 */
    public function __construct(
        private readonly array $satirlar,
        public readonly int $surum,
        public readonly int $maske,
    ) {}

    public function boyut(): int
    {
        return count($this->satirlar);
    }

    public function koyuMu(int $x, int $y): bool
    {
        return ($this->satirlar[$y][$x] ?? 0) === 1;
    }

    /** @return list<list<int>> */
    public function satirlar(): array
    {
        return $this->satirlar;
    }
}
