<?php

namespace Tests\Unit\Domain\Seans;

use App\Services\Seans\SeansTakipServisi;
use PHPUnit\Framework\TestCase;

/** Seans Takibi "Seans Baslangici" kolonu (eski edd3dfff): sureli pakette ilk gelis. */
class SeansBaslangiciTest extends TestCase
{
    public function test_sureli_pakette_ilk_gelis_hic_gelinmediyse_bos(): void
    {
        $this->assertSame('2026-03-04', SeansTakipServisi::seansBaslangici(['sure' => '1 Yıl', 'baslangic' => '2026-03-04'], null));
        $this->assertNull(SeansTakipServisi::seansBaslangici(['sure' => '1 Yıl', 'baslangic' => null], '2026-01-01'));
    }

    public function test_suresiz_kalemde_satis_baslangici(): void
    {
        $this->assertSame('2026-01-01', SeansTakipServisi::seansBaslangici(['sure' => '', 'baslangic' => null], '2026-01-01 00:00:00'));
        $this->assertSame('2026-01-01', SeansTakipServisi::seansBaslangici(null, '2026-01-01'));
        $this->assertNull(SeansTakipServisi::seansBaslangici(null, '0000-00-00'));
    }
}
