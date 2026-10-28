<?php

namespace Tests\Unit;

use App\Domain\Tahsilat\AdisyonHesap;
use PHPUnit\Framework\TestCase;

/**
 * AdisyonHesap saf (kurus) aritmetik testi — DB YOK.
 * KANONIK formul: NET = Σ(fiyat − indirim), ODENEN = Σ tahsilat, KALAN = NET − ODENEN.
 * (urun fiyati satir toplami; hediye zaten fiyat=0; adisyon seviyesi indirim yok.)
 */
class AdisyonHesapTest extends TestCase
{
    public function test_kalem_net_fiyat_eksi_indirim(): void
    {
        $o = AdisyonHesap::ozet(
            [['fiyat' => 100, 'indirim' => 20]],
            []
        );
        $this->assertSame(8000, $o['net_kurus']);
        $this->assertSame(80.0, $o['net']);
        $this->assertSame(0, $o['odenen_kurus']);
        $this->assertSame(8000, $o['kalan_kurus']);
        $this->assertSame('acik', $o['durum']);
    }

    public function test_urun_fiyati_satir_toplami_adetle_carpilmaz(): void
    {
        // fiyat ZATEN satir toplamidir; adet alani yok, carpma yok.
        $o = AdisyonHesap::ozet(
            [['fiyat' => 150, 'indirim' => 0]], // 3 adet x 50 = 150 zaten fiyat'ta
            []
        );
        $this->assertSame(15000, $o['net_kurus']);
    }

    public function test_hediye_fiyat_sifir_sifir_katki(): void
    {
        // Hediye kalemin fiyati zaten 0 yazilir → neti 0.
        $o = AdisyonHesap::ozet(
            [['fiyat' => 0, 'indirim' => 0]],
            []
        );
        $this->assertSame(0, $o['net_kurus']);
    }

    public function test_odenen_ve_kalan(): void
    {
        $o = AdisyonHesap::ozet(
            [
                ['fiyat' => 600, 'indirim' => 0],
                ['fiyat' => 400, 'indirim' => 0],
            ],
            [250, 150] // 400 odenen
        );
        $this->assertSame(100000, $o['net_kurus']);
        $this->assertSame(40000, $o['odenen_kurus']);
        $this->assertSame(60000, $o['kalan_kurus']);
        $this->assertSame('acik', $o['durum']);
    }

    public function test_tam_odenince_kapali(): void
    {
        $o = AdisyonHesap::ozet([['fiyat' => 400, 'indirim' => 0]], [400]);
        $this->assertSame(0, $o['kalan_kurus']);
        $this->assertSame('kapali', $o['durum']); // kalan <= 0 → kapali
    }

    public function test_fazla_odeme_negatif_kalan(): void
    {
        // Kanonik: kalan negatif olabilir (fazla odeme) — clamp YOK.
        $o = AdisyonHesap::ozet([['fiyat' => 100, 'indirim' => 0]], [150]);
        $this->assertSame(-5000, $o['kalan_kurus']);
        $this->assertSame('kapali', $o['durum']);
    }

    public function test_kurus_yuvarlama_hatasiz(): void
    {
        // 33.33 + 33.33 + 33.33 = 99.99 (float tuzagina dusmemeli)
        $o = AdisyonHesap::ozet(
            [
                ['fiyat' => 33.33, 'indirim' => 0],
                ['fiyat' => 33.33, 'indirim' => 0],
                ['fiyat' => 33.33, 'indirim' => 0],
            ],
            [99.99]
        );
        $this->assertSame(9999, $o['net_kurus']);
        $this->assertSame(9999, $o['odenen_kurus']);
        $this->assertSame(0, $o['kalan_kurus']);
    }
}
