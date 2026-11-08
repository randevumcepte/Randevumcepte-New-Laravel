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

    // --- kurus artigi (taksitHavuz / oransal) ---

    public function test_havuz_kurus_artigi_yerlesir(): void
    {
        // 3 x 100 TL kalan, 100 TL odeme: eskiden 33.33 x 3 = 99.99; artik en buyuk bos kapasiteye (esitlikte ilk).
        $o = TahsilatDagitim::taksitHavuz([10000, 10000, 10000], 10000);
        $this->assertSame([3334, 3333, 3333], $o);
        $this->assertSame(10000, array_sum($o));
    }

    public function test_havuz_yukari_yuvarlama_geri_alinir(): void
    {
        // 3 x 100 TL kalan, 200 TL odeme: round(6666.67) x 3 = 20001 → bir kurus geri, toplam 20000.
        $o = TahsilatDagitim::taksitHavuz([10000, 10000, 10000], 20000);
        $this->assertSame(20000, array_sum($o));
        $this->assertSame([6666, 6667, 6667], $o);
    }

    public function test_havuz_toplam_her_zaman_min_odeme_kalan(): void
    {
        foreach ([[[1, 1, 1, 1, 1, 1, 1], 3], [[333, 333, 334], 500], [[12345, 6789, 1], 9999], [[5000, 7000], 20000], [[1, 2, 3], 0]] as [$kalan, $odeme]) {
            $o = TahsilatDagitim::taksitHavuz($kalan, $odeme);
            $this->assertSame(min($odeme, array_sum($kalan)), array_sum($o));
            foreach ($o as $i => $p) {
                $this->assertLessThanOrEqual($kalan[$i], $p);
                $this->assertGreaterThan(0, $p);
            }
        }
    }

    public function test_oransal_sinir_artigi_kapasitesi_olana_gider(): void
    {
        // Fiyat agirligi esit, ilk kalemin kalani 1 TL: payi 1 TL, kalan 99 TL digerlerine esit dilim.
        $o = TahsilatDagitim::oransal([10000, 10000, 10000], [100, 100000, 100000], 30000);
        $this->assertSame([100, 14950, 14950], $o);
        // Kalani 0 olan kalem pay almaz; toplam yine hedef.
        $o = TahsilatDagitim::oransal([10000, 10000, 10000], [0, 10000, 10000], 15000);
        $this->assertSame([1 => 7500, 2 => 7500], $o);
    }

    public function test_oransal_hedef_kapasiteyle_sinirli(): void
    {
        $this->assertSame([2000, 3000], TahsilatDagitim::oransal([1, 1], [2000, 3000], 9000));
        $this->assertSame([], TahsilatDagitim::oransal([1, 1], [0, 0], 9000));
    }
}
