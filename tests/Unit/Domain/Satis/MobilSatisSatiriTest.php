<?php

namespace Tests\Unit\Domain\Satis;

use App\Domain\Satis\MobilSatisSatiri;
use PHPUnit\Framework\TestCase;

/** Eski mobil formatAdisyonFast ile ayni sonuc — DB YOK. */
class MobilSatisSatiriTest extends TestCase
{
    public function test_net_odenen_kalan_ve_bicim(): void
    {
        $s = MobilSatisSatiri::hesapla([
            'hizmetler' => [['ad' => 'Lazer', 'fiyat' => '1000.00', 'indirim' => '100.00', 'tahsilat' => 300, 'personel_id' => 7, 'personel_adi' => 'Ayse ', 'prim_yuzde' => 10]],
            'urunler'   => [['ad' => 'Krem', 'fiyat' => '250.50', 'indirim' => null, 'tahsilat' => 250.5, 'personel_id' => null, 'personel_adi' => '', 'prim_yuzde' => 50]],
        ]);

        $this->assertSame('1.150,50', $s['toplam']);
        $this->assertEqualsWithDelta(1150.5, $s['toplam_numeric'], 1e-9);
        $this->assertSame('550,50', $s['odenen']);
        $this->assertSame(600.0, $s['kalan_tutar_numeric']);
        $this->assertSame('600,00', $s['kalan_tutar']);
        $this->assertSame('acik', $s['durum']);
        $this->assertEqualsWithDelta(1000.0, $s['hizmetToplam'], 1e-9);
        $this->assertEqualsWithDelta(250.5, $s['urunToplam'], 1e-9);
    }

    public function test_hakedis_kalem_bazinda_yuvarlanmaz(): void
    {
        // Kurus yuvarlamasi 0,02 verirdi; eski hesap 0,01 + yuvarlanmamis toplam.
        $kalem = ['ad' => 'x', 'fiyat' => 100, 'indirim' => 0, 'tahsilat' => 0.5, 'personel_id' => 1, 'personel_adi' => 'A', 'prim_yuzde' => 1];
        $s = MobilSatisSatiri::hesapla(['hizmetler' => [$kalem, $kalem]]);

        $this->assertEqualsWithDelta(0.01, $s['hakedisTutar'], 1e-9);
        $this->assertEqualsWithDelta(0.01, $s['hizmetHakedisTutar'], 1e-9);
    }

    public function test_seansli_hizmet_primi_paket_kovasina_ve_etiket(): void
    {
        $s = MobilSatisSatiri::hesapla(['hizmetler' => [
            ['ad' => 'Masaj', 'fiyat' => 500, 'indirim' => 0, 'tahsilat' => 200, 'personel_id' => 3, 'personel_adi' => 'Can', 'seansli' => true, 'seans_sayisi' => 5, 'prim_yuzde' => 20],
        ]]);

        $this->assertEqualsWithDelta(40.0, $s['paketHakedisTutar'], 1e-9);
        $this->assertSame(0, $s['hizmetHakedisTutar']);
        $this->assertSame("Masaj (P) (5 Seans)  Can  500,00 ₺\r\n", $s['icerik']);
        $this->assertSame("Masaj (Can) (P) (5 Seans)  Can  500,00 ₺\r\n", $s['icerikKisaltilmis']);
    }

    public function test_paket_etiketinde_seans_ve_sinirsiz_yazmaz(): void
    {
        $s = MobilSatisSatiri::hesapla(['paketler' => [
            ['ad' => 'Paket', 'fiyat' => 900, 'indirim' => 0, 'tahsilat' => 0, 'personel_id' => null, 'personel_adi' => '', 'seans_sayisi' => 8, 'sinirsiz' => true, 'prim_yuzde' => 0],
        ]]);

        $this->assertSame("Paket (P)    900,00 ₺\r\n", $s['icerik']);
    }

    public function test_kurus_alti_artik_kapali_sayilir(): void
    {
        $s = MobilSatisSatiri::hesapla(['hizmetler' => [
            ['ad' => 'x', 'fiyat' => 0.3, 'indirim' => 0, 'tahsilat' => 0.1 + 0.2, 'personel_id' => null, 'personel_adi' => ''],
        ]]);

        $this->assertSame('kapali', $s['durum']);
        $this->assertSame('0,00', $s['kalan_tutar']);
    }

    public function test_bos_adisyon(): void
    {
        $s = MobilSatisSatiri::hesapla([]);

        $this->assertSame('0,00', $s['toplam']);
        $this->assertSame('kapali', $s['durum']);
        $this->assertSame('', $s['icerik']);
    }
}
