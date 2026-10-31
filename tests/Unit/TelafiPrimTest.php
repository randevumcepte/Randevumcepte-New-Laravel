<?php

namespace Tests\Unit;

use App\Domain\Randevu\TelafiPrim;
use PHPUnit\Framework\TestCase;

/**
 * Telafi prim geri devri — eski TelafiRandevuServisi kuralini kilitler.
 */
class TelafiPrimTest extends TestCase
{
    public function test_hizmet_prim_odenmisse_kazanilan_prim_geri_devredilir(): void
    {
        $this->assertSame(59.0, TelafiPrim::hizmetGeriDevir(590.0, 10.0, true));
    }

    public function test_hizmet_donem_primi_odenmemisse_geri_devir_yok(): void
    {
        $this->assertSame(0.0, TelafiPrim::hizmetGeriDevir(590.0, 10.0, false));
    }

    public function test_hizmet_musteri_odememisse_veya_yuzde_sifirsa_geri_devir_yok(): void
    {
        $this->assertSame(0.0, TelafiPrim::hizmetGeriDevir(0.0, 10.0, true));
        $this->assertSame(0.0, TelafiPrim::hizmetGeriDevir(590.0, 0.0, true));
    }

    public function test_hizmet_kurus_yuvarlama(): void
    {
        $this->assertSame(33.33, TelafiPrim::hizmetGeriDevir(333.33, 10.0, true));
    }

    public function test_paket_seans_basi_prim(): void
    {
        // 3000 TL / 10 seans = 300; %15 → 45
        $this->assertSame(45.0, TelafiPrim::paketGeriDevir(3000.0, 10, 15.0, true));
    }

    public function test_paket_tahsilat_veya_seans_yoksa_sifir(): void
    {
        $this->assertSame(0.0, TelafiPrim::paketGeriDevir(0.0, 10, 15.0, true));
        $this->assertSame(0.0, TelafiPrim::paketGeriDevir(3000.0, 0, 15.0, true));
        $this->assertSame(0.0, TelafiPrim::paketGeriDevir(3000.0, 10, 15.0, false));
    }
}
