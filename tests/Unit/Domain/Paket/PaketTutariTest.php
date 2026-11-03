<?php

namespace Tests\Unit\Domain\Paket;

use App\Domain\Paket\PaketTutari;
use PHPUnit\Framework\TestCase;

class PaketTutariTest extends TestCase
{
    public function test_web_paketi_hizmet_degeri_yoksa_paket_duzeyi(): void
    {
        $this->assertSame(['fiyat' => 1500.0, 'seans' => 10], PaketTutari::etkin('1500', 10, [['hizmet_id' => 3], ['seans' => null, 'fiyat' => null]]));
    }

    public function test_mobil_paketi_hizmet_toplami_onceliklidir(): void
    {
        $this->assertSame(['fiyat' => 900.5, 'seans' => 8], PaketTutari::etkin(1000, 6, [['seans' => 5, 'fiyat' => '600.25'], ['seans' => 3, 'fiyat' => 300.25]]));
    }

    public function test_alanlar_bagimsiz_cozulur(): void
    {
        // Fiyat hizmetlerden, seans paket duzeyinden (eski COALESCE(NULLIF(SUM)) ile ayni).
        $this->assertSame(['fiyat' => 400.0, 'seans' => 12], PaketTutari::etkin(999, 12, [['fiyat' => 400, 'seans' => 0]]));
    }

    public function test_hic_deger_yoksa_sifir(): void
    {
        $this->assertSame(['fiyat' => 0.0, 'seans' => 0], PaketTutari::etkin(null, null, []));
    }
}
