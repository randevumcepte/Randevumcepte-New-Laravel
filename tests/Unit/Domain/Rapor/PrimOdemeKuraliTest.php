<?php

namespace Tests\Unit\Domain\Rapor;

use App\Domain\Rapor\PrimOdemeKurali;
use PHPUnit\Framework\TestCase;

/** Odeme ve bonus/kesinti girdi kurallari, otomatik masraf metinleri — eski primOdeApi ile parite — DB YOK. */
class PrimOdemeKuraliTest extends TestCase
{
    public function test_tutar_virgul_nokta_olur(): void
    {
        $this->assertSame(1234.5, PrimOdemeKurali::tutar('1234,5'));
        $this->assertSame(150.0, PrimOdemeKurali::tutar('150.00'));
        $this->assertSame(0.0, PrimOdemeKurali::tutar(null));
    }

    public function test_tarih_ve_donem(): void
    {
        $this->assertSame('2026-01-02', PrimOdemeKurali::tarih('2026-01-02', '2026-03-03'));
        $this->assertSame('2026-03-03', PrimOdemeKurali::tarih('02.01.2026', '2026-03-03'));
        $this->assertSame('2026-03-03', PrimOdemeKurali::tarih(null, '2026-03-03'));
        $this->assertTrue(PrimOdemeKurali::donemGecerli('2026-03'));
        $this->assertFalse(PrimOdemeKurali::donemGecerli('2026-3'));
        $this->assertSame(['2024-02', '2024-02-01', '2024-02-29'], PrimOdemeKurali::listeDonemi('2024-02', '2026-03'));
        $this->assertSame(['2026-03', '2026-03-01', '2026-03-31'], PrimOdemeKurali::listeDonemi(null, '2026-03'));
    }

    public function test_tipler(): void
    {
        $this->assertSame('maas', PrimOdemeKurali::odemeTipi('maas'));
        $this->assertSame('diger', PrimOdemeKurali::odemeTipi('avans'));
        $this->assertSame('diger', PrimOdemeKurali::odemeTipi(null));
        $this->assertSame('kesinti', PrimOdemeKurali::hareketTipi('kesinti'));
        $this->assertSame('bonus', PrimOdemeKurali::hareketTipi('baska'));
        $this->assertSame('2026-01-02', PrimOdemeKurali::primGun('prim', '2026-01-02'));
        $this->assertNull(PrimOdemeKurali::primGun('maas', '2026-01-02'));
        $this->assertNull(PrimOdemeKurali::primGun('prim', 'x'));
    }

    public function test_masraf_metinleri_eskiyle_ayni(): void
    {
        $this->assertSame('Maaş Ödemesi (2026-03)', PrimOdemeKurali::masrafAciklamasi('maas', '2026-03', ''));
        $this->assertSame('Prim Ödemesi (2026-03) — not', PrimOdemeKurali::masrafAciklamasi('prim', '2026-03', 'not'));
        $this->assertSame('Avans/Bonus (2026-03)', PrimOdemeKurali::masrafAciklamasi('diger', '2026-03', null));
        $this->assertSame(mb_strlen('Prim Ödemesi (2026-03) — ') + 80, mb_strlen(PrimOdemeKurali::masrafAciklamasi('prim', '2026-03', str_repeat('ş', 100))));
        $this->assertSame('Personel: Ayşe — Maas (2026-03)', PrimOdemeKurali::masrafNotu('Ayşe', 'maas', '2026-03'));
        $this->assertSame('Personel:  — Diger (2026-03)', PrimOdemeKurali::masrafNotu(null, 'diger', '2026-03'));
        $this->assertSame(60, mb_strlen(PrimOdemeKurali::kes(str_repeat('ş', 70), 60)));
    }
}
