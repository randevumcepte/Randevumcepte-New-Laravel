<?php

namespace Tests\Unit\Domain\Paket;

use App\Services\Paket\PaketSurumu;
use PHPUnit\Framework\TestCase;

class PaketSurumuTest extends TestCase
{
    public function test_sira_ve_tip_farki_degisiklik_sayilmaz(): void
    {
        $this->assertTrue(PaketSurumu::ayniHizmetler([3, 7, 9], ['9', '3', 7]));
        $this->assertTrue(PaketSurumu::ayniHizmetler([3, 3, 7], [7, 3]));
    }

    public function test_hizmet_cikarilinca_veya_eklenince_degisiklik_sayilir(): void
    {
        $this->assertFalse(PaketSurumu::ayniHizmetler([3, 7, 9], [3, 7]));
        $this->assertFalse(PaketSurumu::ayniHizmetler([3, 7], [3, 7, 9]));
        $this->assertFalse(PaketSurumu::ayniHizmetler([3, 7], [3, 8]));
    }
}
