<?php

namespace Tests\Unit;

use App\Support\KayitKodKapisi;
use PHPUnit\Framework\TestCase;

/** Kayitta duz tutulan kod (randevu geldi, KVKK) karsilastirmasi: sabit zamanli, bos kayit ya da bos giris gecmez. */
class KayitKodKapisiTest extends TestCase
{
    public function test_bos_kod_hicbir_zaman_eslesmez(): void
    {
        $this->assertFalse(KayitKodKapisi::eslesir('', ''));
        $this->assertFalse(KayitKodKapisi::eslesir(' ', ' '));
        $this->assertFalse(KayitKodKapisi::eslesir('', '1234'));
        $this->assertFalse(KayitKodKapisi::eslesir('1234', ''));
    }

    public function test_dolu_kod_bosluk_kirpilarak_eslesir(): void
    {
        $this->assertTrue(KayitKodKapisi::eslesir('1234', '1234'));
        $this->assertTrue(KayitKodKapisi::eslesir(' 1234', '1234 '));
        $this->assertFalse(KayitKodKapisi::eslesir('1234', '1235'));
        $this->assertFalse(KayitKodKapisi::eslesir('1234', '12345'));
    }
}
