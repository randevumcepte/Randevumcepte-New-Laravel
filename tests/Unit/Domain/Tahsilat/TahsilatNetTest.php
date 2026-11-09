<?php

namespace Tests\Unit\Domain\Tahsilat;

use App\Domain\Tahsilat\TahsilatNet;
use PHPUnit\Framework\TestCase;

/** Tahsilat net tutari (brut - komisyon) ve duzenlemede azaltma kapisi — tek saf tanim, kurus-kesin. DB YOK. */
class TahsilatNetTest extends TestCase
{
    public function test_net_kurus_komisyon_dusulur_negatife_inmez(): void
    {
        $this->assertSame(100, TahsilatNet::kurus(1000, 999));
        $this->assertSame(0, TahsilatNet::kurus(10, 20));
        $this->assertSame(30, TahsilatNet::kurus(0.1 + 0.2, 0));
        $this->assertSame(125050, TahsilatNet::kurus('1250.50'));
    }

    public function test_azaltma_net_uzerinden(): void
    {
        $this->assertTrue(TahsilatNet::azaltmaMi(100.0, null), 'silme');
        $this->assertTrue(TahsilatNet::azaltmaMi(100.0, 99.99));
        $this->assertFalse(TahsilatNet::azaltmaMi(100.0, 100.0));
        $this->assertFalse(TahsilatNet::azaltmaMi(0.3, 0.1 + 0.2), 'kurus-kesin karsilastirma');
        $this->assertFalse(TahsilatNet::azaltmaMi(100.0, 150.0));
    }

    public function test_komisyonla_net_dusurme_azaltmadir(): void
    {
        // {tutar:1000, komisyon:999}: brut ayni ama net 1 TL → onay + tahsilat_sil gerekir.
        $this->assertTrue(TahsilatNet::azaltmaMi(1000.0, 1000.0, 999.0));
        $this->assertTrue(TahsilatNet::azaltmaMi(1000.0, 1500.0, 500.01));
        $this->assertFalse(TahsilatNet::azaltmaMi(1000.0, 1500.0, 500.0), 'net ayni');
        $this->assertFalse(TahsilatNet::azaltmaMi(1000.0, 1100.0, 99.99), 'net artar');
        $this->assertTrue(TahsilatNet::azaltmaMi(0.01, 10.0, 10.0), 'net sifira iner');
    }
}
