<?php

namespace Tests\Unit\Domain\Satis;

use App\Domain\Satis\MobilPaketSeansi;
use App\Domain\Satis\MusteriDanisanTuru;
use PHPUnit\Framework\TestCase;

/** Eski mobil musteri turu ve paket seans kurali — DB YOK. */
class MobilSatisKurallariTest extends TestCase
{
    private const SIMDI = 1_800_000_000;

    public function test_musteri_turu(): void
    {
        $once = date('Y-m-d H:i:s', self::SIMDI - 86400);

        $this->assertSame(0, MusteriDanisanTuru::hesapla(0, null, self::SIMDI));
        $this->assertSame(1, MusteriDanisanTuru::hesapla(3, $once, self::SIMDI));
        $this->assertSame(2, MusteriDanisanTuru::hesapla(4, $once, self::SIMDI));
        // Eski kosul: son kayit simdiden sonraysa 2 sayilmaz.
        $this->assertSame(1, MusteriDanisanTuru::hesapla(4, date('Y-m-d H:i:s', self::SIMDI + 60), self::SIMDI));
    }

    public function test_paket_seansi(): void
    {
        $this->assertSame(6, MobilPaketSeansi::etkin('6', 10));
        $this->assertSame(0, MobilPaketSeansi::etkin('0', 10));
        $this->assertSame(10, MobilPaketSeansi::etkin('', 10));
        $this->assertSame(10, MobilPaketSeansi::etkin(null, 10));
    }

    public function test_sinirsiz_duzenleme(): void
    {
        $this->assertTrue(MobilPaketSeansi::sinirsizDuzenleme(true, 1, '5', 5));
        $this->assertFalse(MobilPaketSeansi::sinirsizDuzenleme(false, 1, '5', 5));
        $this->assertFalse(MobilPaketSeansi::sinirsizDuzenleme(true, 0, '5', 5));
        $this->assertFalse(MobilPaketSeansi::sinirsizDuzenleme(true, 1, '5', 6));
    }
}
