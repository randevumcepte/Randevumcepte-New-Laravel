<?php

namespace Tests\Unit;

use App\Domain\Randevu\SaatKapama;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SaatKapamaTest extends TestCase
{
    public function test_tekrarsiz_tek_tarih(): void
    {
        $this->assertSame(['2026-11-03'], SaatKapama::tarihler('2026-11-03'));
    }

    public function test_haftalik_tekrar_toplam_sayi_kadar(): void
    {
        $this->assertSame(['2026-11-03', '2026-11-10', '2026-11-17'], SaatKapama::tarihler('2026-11-03', 3, '+1 week'));
    }

    public function test_gunluk_tekrar_ay_donumu(): void
    {
        $this->assertSame(['2026-11-29', '2026-11-30', '2026-12-01'], SaatKapama::tarihler('2026-11-29', 3, '+1 day'));
    }

    public function test_beyaz_liste_disi_siklik_reddedilir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SaatKapama::tarihler('2026-11-03', 2, 'next monday');
    }

    public function test_ust_sinir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SaatKapama::tarihler('2026-11-03', 53, '+1 day');
    }

    public function test_sure_ve_saat_normalize(): void
    {
        $this->assertSame(90, SaatKapama::sureDk('10:00', '11:30:00'));
        $this->assertSame('09:05:00', SaatKapama::saat('09:05'));
    }

    public function test_bitis_baslangictan_once_reddedilir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SaatKapama::sureDk('12:00', '11:00');
    }
}
