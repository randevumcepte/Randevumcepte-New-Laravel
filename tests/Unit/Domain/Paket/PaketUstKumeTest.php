<?php

namespace Tests\Unit\Domain\Paket;

use App\Domain\Paket\PaketUstKume;
use PHPUnit\Framework\TestCase;

class PaketUstKumeTest extends TestCase
{
    public function test_aday_mevcut_tum_hizmetleri_kapsamali_ve_ek_hizmeti_olmali(): void
    {
        $this->assertTrue(PaketUstKume::adayMi([1, 2], [1, 2, 3]));
        $this->assertTrue(PaketUstKume::adayMi(['2', '1'], [3, 2, 1, 1]));
        // Ek hizmet yok (ayni kume) → yukseltme degil.
        $this->assertFalse(PaketUstKume::adayMi([1, 2], [2, 1]));
        // Mevcut hizmetlerden biri hedefte yok → mevcut seans kayitlari gecersiz kalir.
        $this->assertFalse(PaketUstKume::adayMi([1, 2], [1, 3, 4]));
        $this->assertFalse(PaketUstKume::adayMi([1], []));
    }

    public function test_ek_hizmetler(): void
    {
        $this->assertSame([3, 4], PaketUstKume::ekHizmetler([1, 2], [1, 2, 3, 4]));
        $this->assertSame([], PaketUstKume::ekHizmetler([1, 2], [1, 2]));
    }

    public function test_bekleyen_seans_kapasite_eksi_acilan_sifir_alti_yok(): void
    {
        $this->assertSame(19, PaketUstKume::bekleyenSeans(8, 3, 5));
        $this->assertSame(0, PaketUstKume::bekleyenSeans(2, 2, 9));
        $this->assertSame(8, PaketUstKume::bekleyenSeans(8, 0, 0));
    }

    public function test_kalan_sifir_altina_dusmez(): void
    {
        $this->assertSame(500.0, PaketUstKume::kalan(1500.0, 1000.0));
        $this->assertSame(0.0, PaketUstKume::kalan(1000.0, 1200.0));
        $this->assertSame(0.1, PaketUstKume::kalan(0.3, 0.2));
    }
}
