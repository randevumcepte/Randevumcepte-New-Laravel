<?php

namespace Tests\Unit\Domain\Satis;

use App\Domain\Satis\AdisyonSatir;
use PHPUnit\Framework\TestCase;

/**
 * AdisyonSatir saf (kurus) matematik + icerik/hakediş paritesi — DB YOK.
 * KANONIK: eski ApiController@formatAdisyonFast ile birebir.
 */
class AdisyonSatirTest extends TestCase
{
    public function test_net_fiyat_eksi_indirim_ve_durum(): void
    {
        $s = AdisyonSatir::hesapla([
            'hizmetler' => [
                ['ad' => 'Masaj', 'fiyat' => 1000, 'indirim' => 200, 'tahsilat' => 300, 'personel_id' => 5, 'personel_adi' => 'Ali', 'prim_yuzde' => 0],
            ],
        ]);

        // NET = 1000 - 200 = 800 ; odenen = 300 ; kalan = 500 ; acik
        $this->assertSame(80000, $s['toplam_kurus']);
        $this->assertSame(30000, $s['odenen_kurus']);
        $this->assertSame(50000, $s['kalan_kurus']);
        $this->assertSame('800,00', $s['toplam']);
        $this->assertSame('300,00', $s['odenen']);
        $this->assertSame('500,00', $s['kalan_tutar']);
        $this->assertSame('acik', $s['durum']);
        // BRUT toplam indirim dusulmez
        $this->assertSame(1000.0, $s['hizmet_toplam']);
    }

    public function test_kalan_sifir_kapali(): void
    {
        $s = AdisyonSatir::hesapla([
            'urunler' => [
                ['ad' => 'Krem', 'fiyat' => 150, 'indirim' => 0, 'tahsilat' => 150, 'personel_id' => null, 'personel_adi' => '', 'prim_yuzde' => 0],
            ],
        ]);
        $this->assertSame(0, $s['kalan_kurus']);
        $this->assertSame('kapali', $s['durum']);
        $this->assertSame(150.0, $s['urun_toplam']);
    }

    public function test_hizmet_primi_genel_oran(): void
    {
        // tahsilat 400 * %10 = 40 hizmet hakediş
        $s = AdisyonSatir::hesapla([
            'hizmetler' => [
                ['ad' => 'Kesim', 'fiyat' => 500, 'indirim' => 0, 'tahsilat' => 400, 'personel_id' => 7, 'personel_adi' => 'Veli', 'prim_yuzde' => 10.0],
            ],
        ]);
        $this->assertSame(40.0, $s['hizmet_hakedis']);
        $this->assertSame(40.0, $s['hakedis']);
        $this->assertSame(0.0, $s['paket_hakedis']);
    }

    public function test_seansli_hizmet_primi_paket_kovasina(): void
    {
        // Seansli hizmet: prim PAKET kovasina (eski paket_prim_yuzde mantigi).
        $s = AdisyonSatir::hesapla([
            'hizmetler' => [
                ['ad' => 'Lazer', 'fiyat' => 2000, 'indirim' => 0, 'tahsilat' => 1000, 'personel_id' => 9, 'personel_adi' => 'Ece', 'prim_yuzde' => 20.0, 'seansli' => true, 'seans_sayisi' => 8],
            ],
        ]);
        $this->assertSame(0.0, $s['hizmet_hakedis']);
        $this->assertSame(200.0, $s['paket_hakedis']);   // 1000 * %20
        $this->assertStringContainsString('(P) (8 Seans)', $s['icerik']);
    }

    public function test_personel_yoksa_prim_yok(): void
    {
        $s = AdisyonSatir::hesapla([
            'hizmetler' => [
                ['ad' => 'X', 'fiyat' => 100, 'indirim' => 0, 'tahsilat' => 100, 'personel_id' => null, 'personel_adi' => '', 'prim_yuzde' => 50.0],
            ],
        ]);
        $this->assertSame(0.0, $s['hakedis']);
    }

    public function test_icerik_ve_hizmet_veren_etiketleri(): void
    {
        $s = AdisyonSatir::hesapla([
            'hizmetler' => [
                ['ad' => 'Sac', 'fiyat' => 100, 'indirim' => 0, 'tahsilat' => 0, 'personel_id' => 1, 'personel_adi' => 'Ali', 'prim_yuzde' => 0],
            ],
            'urunler' => [
                ['ad' => 'Sampuan', 'fiyat' => 50, 'indirim' => 0, 'tahsilat' => 0, 'personel_id' => 2, 'personel_adi' => 'Veli', 'prim_yuzde' => 0],
            ],
            'paketler' => [
                ['ad' => 'Bakim', 'fiyat' => 300, 'indirim' => 0, 'tahsilat' => 0, 'personel_id' => 1, 'personel_adi' => 'Ali', 'seans_sayisi' => 4, 'prim_yuzde' => 0],
            ],
        ]);
        $this->assertStringContainsString('(H)', $s['icerik']);
        $this->assertStringContainsString('(Ü)', $s['icerik']);
        $this->assertStringContainsString('(P) (4 Seans)', $s['icerik']);
        // hizmet_veren = tum personel adlari bosluklu, trim'li
        $this->assertSame('Ali Veli Ali', $s['hizmet_veren']);
        // NET = 100+50+300 = 450
        $this->assertSame(45000, $s['toplam_kurus']);
    }

    public function test_tr_format(): void
    {
        $this->assertSame('1.234,56', AdisyonSatir::trFormat(1234.56));
        $this->assertSame('0,00', AdisyonSatir::trFormat(0));
    }
}
