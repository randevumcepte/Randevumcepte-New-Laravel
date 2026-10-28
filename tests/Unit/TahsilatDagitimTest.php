<?php

namespace Tests\Unit;

use App\Domain\Tahsilat\TahsilatDagitim;
use PHPUnit\Framework\TestCase;

/**
 * TahsilatDagitim saf (kuruş) dağıtım testi — eski WEB kanonik davranışını kilitler.
 */
class TahsilatDagitimTest extends TestCase
{
    // --- reconcile ---

    public function test_reconcile_tam_bolunur(): void
    {
        $p = TahsilatDagitim::reconcile([60000, 40000], 100000);
        $this->assertSame([60000, 40000], $p);
        $this->assertSame(100000, array_sum($p));
    }

    public function test_reconcile_kurus_artigi_en_buyuk_kaleme(): void
    {
        // 3 esit kalem, hedef 10000: her biri round(3333.33)=3333, toplam 9999,
        // artik 1 kurus → ilk (en buyuk/esit) kaleme eklenir.
        $p = TahsilatDagitim::reconcile([10000, 10000, 10000], 10000);
        $this->assertSame(10000, array_sum($p));
        $this->assertSame([3334, 3333, 3333], $p);
    }

    public function test_reconcile_orantili(): void
    {
        // raw 70000 / 30000, hedef 50000 → 35000 / 15000
        $p = TahsilatDagitim::reconcile([70000, 30000], 50000);
        $this->assertSame(50000, array_sum($p));
        $this->assertSame([35000, 15000], $p);
    }

    public function test_reconcile_bos(): void
    {
        $this->assertSame([], TahsilatDagitim::reconcile([], 10000));
    }

    // --- taksitHavuz ---

    public function test_havuz_orantili_dagitim(): void
    {
        // kalan 60000/40000, kismi 50000 → dagitilacak 50000 → 30000/20000
        $o = TahsilatDagitim::taksitHavuz([60000, 40000], 50000);
        $this->assertSame([30000, 20000], $o);
        $this->assertSame(50000, array_sum($o));
    }

    public function test_havuz_kismi_odeme_kalandan_az(): void
    {
        // kalan tek kalem 10000, kismi 5000 → pay 5000 (<= kalan)
        $o = TahsilatDagitim::taksitHavuz([10000], 5000);
        $this->assertSame([5000], $o);
    }

    public function test_havuz_fazla_odeme_kalanla_capli(): void
    {
        // kalan 10000, kismi 15000 → dagitilacak min(15000,10000)=10000, pay=10000 (cap)
        $o = TahsilatDagitim::taksitHavuz([10000], 15000);
        $this->assertSame([10000], $o);
    }

    public function test_havuz_kalani_sifir_olanlar_elenir(): void
    {
        // index 0 kalan 0 (elenir), index 1 kalan 90000
        $o = TahsilatDagitim::taksitHavuz([0, 90000], 90000);
        $this->assertArrayNotHasKey(0, $o);
        $this->assertSame(90000, $o[1]);
    }

    public function test_havuz_hic_kalan_yok(): void
    {
        $this->assertSame([], TahsilatDagitim::taksitHavuz([0, 0], 5000));
    }
}
