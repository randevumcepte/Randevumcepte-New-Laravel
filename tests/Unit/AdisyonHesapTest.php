<?php

namespace Tests\Unit;

use App\Domain\Tahsilat\AdisyonHesap;
use PHPUnit\Framework\TestCase;

/**
 * AdisyonHesap saf (kurus) aritmetik testi — DB YOK.
 * net/kalan formulu GECICI; bu test formulun BUGUNKU davranisini kilitler ki
 * shadow sonrasi degisiklikler bilincli olsun.
 */
class AdisyonHesapTest extends TestCase
{
    public function test_hizmet_net_fiyat_eksi_indirim(): void
    {
        $o = AdisyonHesap::ozet(
            [['tip' => 'hizmet', 'fiyat' => 100, 'indirim' => 20, 'adet' => 1, 'hediye' => false]],
            []
        );
        $this->assertSame(8000, $o['net_kurus']);
        $this->assertSame(80.0, $o['net']);
        $this->assertSame(0, $o['odenen_kurus']);
        $this->assertSame(8000, $o['kalan_kurus']);
    }

    public function test_urun_adet_ile_carpilir(): void
    {
        $o = AdisyonHesap::ozet(
            [['tip' => 'urun', 'fiyat' => 50, 'indirim' => 0, 'adet' => 3, 'hediye' => false]],
            []
        );
        $this->assertSame(15000, $o['net_kurus']); // 50 * 3
    }

    public function test_hediye_kalem_sifir(): void
    {
        $o = AdisyonHesap::ozet(
            [['tip' => 'hizmet', 'fiyat' => 100, 'indirim' => 0, 'adet' => 1, 'hediye' => true]],
            []
        );
        $this->assertSame(0, $o['net_kurus']);
    }

    public function test_odenen_ve_kalan(): void
    {
        $o = AdisyonHesap::ozet(
            [
                ['tip' => 'hizmet', 'fiyat' => 600, 'indirim' => 0, 'adet' => 1, 'hediye' => false],
                ['tip' => 'urun', 'fiyat' => 200, 'indirim' => 0, 'adet' => 2, 'hediye' => false], // 400
            ],
            [250, 150] // toplam 400 odenen
        );
        $this->assertSame(100000, $o['net_kurus']);   // 600 + 400
        $this->assertSame(40000, $o['odenen_kurus']);  // 250 + 150
        $this->assertSame(60000, $o['kalan_kurus']);
        $this->assertSame(600.0, $o['kalan']);
    }

    public function test_kurus_yuvarlama_hatasiz(): void
    {
        // 33.33 * 3 = 99.99 (float 99.98999... tuzagina dusmemeli)
        $o = AdisyonHesap::ozet(
            [['tip' => 'urun', 'fiyat' => 33.33, 'indirim' => 0, 'adet' => 3, 'hediye' => false]],
            [99.99]
        );
        $this->assertSame(9999, $o['net_kurus']);
        $this->assertSame(9999, $o['odenen_kurus']);
        $this->assertSame(0, $o['kalan_kurus']);
    }

    public function test_net_negatif_olmaz_kalem_bazinda(): void
    {
        // indirim fiyattan buyukse kalem neti 0'a sabitlenir (negatif tasmaz).
        $o = AdisyonHesap::ozet(
            [['tip' => 'hizmet', 'fiyat' => 100, 'indirim' => 150, 'adet' => 1, 'hediye' => false]],
            []
        );
        $this->assertSame(0, $o['net_kurus']);
    }
}
