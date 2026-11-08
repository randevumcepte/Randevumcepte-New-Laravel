<?php

namespace Tests\Unit;

use App\Domain\Tahsilat\MobilTahsilatHesabi as H;
use PHPUnit\Framework\TestCase;

/**
 * Mobil para metni ve satir hesaplarini kilitler. Odeme ve indirim dagitimi web ile ayni koddadir (TahsilatDagitim,
 * TahsilatYazServisi); eski mobil dagitim formulleri (kalemPayi, vadeKalemPayi, hariciPay) kullanici karariyla kaldirildi.
 */
class MobilTahsilatHesabiTest extends TestCase
{
    public function test_tl_cevrimi_veritabani_degerinde_de_noktayi_siler(): void
    {
        $this->assertSame(1234.5, H::tlSayi('1.234,50'));
        $this->assertSame(150000.0, H::tlSayi('1500.00'));
        $this->assertSame(0.0, H::tlSayi(null));
    }

    public function test_php74_karsilastirmasi(): void
    {
        $this->assertTrue(H::sifirdanBuyukMu('1.234,50'));
        $this->assertFalse(H::sifirdanBuyukMu('0,00'));
        $this->assertFalse(H::sifirdanBuyukMu('0,50'));
        $this->assertFalse(H::sifirdanBuyukMu(null));
        $this->assertFalse(H::senetOnOdemesiVarMi('0,00'));
        $this->assertFalse(H::senetOnOdemesiVarMi(''));
        $this->assertTrue(H::senetOnOdemesiVarMi('100,00'));
    }

    public function test_odeme_komisyondan_ayrilir_negatife_dusmez(): void
    {
        $this->assertSame([900.0, 100.0], H::odemeAyir('1.000,00', '100,00'));
        $this->assertSame([0.0, 200.0], H::odemeAyir('100,00', '200,00'));
        // Ondalik nokta ve binlik ayirici da kurus-kesin (eski tlSayi "1500.00"i 150000 okurdu).
        $this->assertSame([1450.5, 50.0], H::odemeAyir('1500.50', '50'));
        $this->assertSame([1500.0, 0.0], H::odemeAyir('1.500', null));
    }

    public function test_musteri_indirimi_haricisiz_oransal_kalanla_sinirli_kurus_kesin(): void
    {
        $this->assertSame(5000, H::musteriIndirimKurus('150,00', '100,00'));
        $this->assertSame(0, H::musteriIndirimKurus('50,00', '100,00'));
        // 2 x 100 TL, 50 TL indirim: 25 / 25.
        $this->assertSame([2500, 2500], H::musteriIndirimPaylari([10000, 10000], [true, true], [10000, 10000], 5000));
        // Ikinci kalemin 90 TL'si odenmis: payi 10 TL ile sinirli, sinir artigi (15 TL) birinciye (eski: 25 / 10, 15 TL kayip).
        $this->assertSame([4000, 1000], H::musteriIndirimPaylari([10000, 10000], [true, true], [10000, 1000], 5000));
        // 3 x 100 TL, 100 TL: 33.34 + 33.33 + 33.33 (eski 33.33 x 3 = 99.99).
        $this->assertSame([3334, 3333, 3333], H::musteriIndirimPaylari([10000, 10000, 10000], [true, true, true], [10000, 10000, 10000], 10000));
        // Indirimi olan kalem atlanir, payi baskasina aktarilmaz.
        $this->assertSame([2500], H::musteriIndirimPaylari([10000, 10000], [true, false], [10000, 10000], 5000));
        $this->assertSame([], H::musteriIndirimPaylari([10000], [false], [10000], 5000));
    }

    public function test_kampanya_indirimi_web_oransal_kurali(): void
    {
        // Kampanya kodu indirimi TahsilatDagitim::oransal ile: 3 x 100 TL'ye 100 TL -> 33,34 + 33,33 + 33,33.
        $this->assertSame([3334, 3333, 3333], \App\Domain\Tahsilat\TahsilatDagitim::oransal([10000, 10000, 10000], [10000, 10000, 10000], 10000));
        // Kalani 10 TL olan kalem 10 TL alir, artan diger kalemlere gider (eskiden kayboluyordu).
        $this->assertSame([4500, 1000, 4500], \App\Domain\Tahsilat\TahsilatDagitim::oransal([10000, 10000, 10000], [10000, 1000, 10000], 10000));
    }

    public function test_para_kurus_bicimleri(): void
    {
        $this->assertSame(123456, H::paraKurus('1.234,56'));
        $this->assertSame(10000, H::paraKurus('100.00'));
        $this->assertSame(150000, H::paraKurus('1.500'));
        $this->assertSame(123456700, H::paraKurus('1.234.567'));
        $this->assertSame(150, H::paraKurus('1.5'));
        $this->assertSame(0, H::paraKurus(null));
        $this->assertSame(5000, H::paraKurus(50));
    }

    public function test_vade_tutari_ve_tarihleri(): void
    {
        $this->assertSame('333.33', H::vadeTutari('1.000,00', 3));
        $this->assertSame(['2026-01-31', '2026-03-03', '2026-04-03'], H::vadeTarihleri('2026-01-31', 3));
        $this->assertSame(['2026-01-01', '2026-01-08'], H::seansTarihleri('2026-01-01', 2, 7));
    }

    public function test_kalem_basina_indirim_ve_sifir_kalem_hatasi(): void
    {
        $this->assertSame(33.33, H::kalemBasinaIndirim('50,00', '50,00', 3));
        $this->expectException(\DivisionByZeroError::class);
        H::kalemBasinaIndirim('0', '0', 0);
    }

    public function test_on_odeme_paylari_web_kurali_tek_havuz_kurus_kesin(): void
    {
        $this->assertSame([166.67, 333.33], H::onOdemePaylari(['100.00', '200.00'], 0.0, '500,00'));
        // "100.00" 100 TL okunur; net = fiyat - kalem basina indirim (eski formul 10000 okuyordu: 166.39 / 333.61).
        $this->assertSame([125.0, 375.0], H::onOdemePaylari(['100.00', '200.00'], 50.0, '500,00'));
        // Hizmet 100 + urun 50 + paket 150 tek listede: paylar odemeyi bir kez boler.
        $this->assertSame([100.0, 50.0, 150.0], H::onOdemePaylari(['100,00', '50,00', '150,00'], 0.0, '300,00'));
        // Kurus artigi en buyuk kaleme: toplam odemeye birebir esit (33.33 x 3 = 99.99 olmaz).
        $paylar = H::onOdemePaylari(['100.00', '100.00', '100.00'], 0.0, '100,00');
        $this->assertSame([33.34, 33.33, 33.33], $paylar);
        $this->assertSame(10000, (int) round(array_sum($paylar) * 100));
        $this->assertSame([1234.56], H::onOdemePaylari(['1.234,56'], 0.0, '1.234,56'));
    }

    public function test_tum_alacaklar_kalani_ve_gorunurluk(): void
    {
        $this->assertSame(600.0, H::kalemKalani('1000.00', '300.00', '100.00'));
        $this->assertSame(1000.0, H::kalemKalani('1000.00', 0, null));
        $this->assertTrue(H::alacakGorunurMu(1.0, 0, null, null));
        $this->assertTrue(H::alacakGorunurMu(0.0, 1, null, null));
        $this->assertFalse(H::alacakGorunurMu(0.0, 0, null, null));
        $this->assertFalse(H::alacakGorunurMu(1.0, 0, 5, null));
        $this->assertFalse(H::alacakGorunurMu(1.0, 0, null, 5));
    }

    public function test_harici_tahsilat(): void
    {
        $this->assertSame(1000.5, H::hariciFiyat('1000,50'));
        $this->assertSame(100.56, H::hariciFiyat('100.555'));
        $this->assertSame(0.0, H::hariciFiyat('-5'));
        $this->assertSame(1.0, H::hariciAdet('0'));
        $this->assertSame(300.0, H::hariciTahsilatTutari('500', 300.0));
        $this->assertSame(0.0, H::hariciTahsilatTutari('500', 0.0));
    }

    public function test_kampanya_yuzdesi(): void
    {
        $this->assertSame([20, 'yuzde'], H::kampanyaIndirimi('%20 indirim'));
        $this->assertSame([0, 'xalyode'], H::kampanyaIndirimi('3 Al 2 Öde'));
        $this->assertSame(36.0, H::yuzdeIndirim(180.0, 20));
    }

    public function test_hizli_ve_telefon_satisi(): void
    {
        $this->assertSame(['birim' => 50.0, 'adet' => 2.0, 'tutar' => 100.0], H::hizliSatisSatiri('50', '80', -2));
        $this->assertSame(['birim' => 80.0, 'adet' => 1.0, 'tutar' => 80.0], H::hizliSatisSatiri(null, '80', null));
        $this->assertSame(['birim' => 33.333, 'adet' => 3.0, 'tutar' => 100.0], H::hizliSatisSatiri('33.333', null, 3));
        $this->assertSame(1000.56, H::telefonFiyati('1000,555'));
    }

    public function test_ongorusme_fiyat_kurali(): void
    {
        $this->assertSame(250.0, H::paketSatisFiyati(100, 200, '250'));
        $this->assertSame(100.0, H::paketSatisFiyati(100, 200, '1.000,00'));
        $this->assertSame(200.0, H::paketSatisFiyati(0, 200, ''));
        $this->assertSame(250.0, H::urunSatisFiyati(100, 3, '250'));
        $this->assertSame(300.0, H::urunSatisFiyati(100, 3, null));
        $this->assertSame(8, H::paketSeansSayisi('', 8, 3));
        $this->assertSame(3, H::paketSeansSayisi('', 0, 3));
        $this->assertSame(5, H::paketSeansSayisi('5', 8, 3));
        $this->assertNull(H::hizmetSeansSayisi('1'));
        $this->assertSame(4, H::hizmetSeansSayisi('4'));
    }

    public function test_kapali_adisyon(): void
    {
        $this->assertFalse(H::tamamenOdenmisMi(0.0, 0.0));
        $this->assertTrue(H::tamamenOdenmisMi(100.0, 99.999));
        $this->assertFalse(H::tamamenOdenmisMi(100.0, 99.99));
    }

    public function test_kalem_ozeti_ve_alacak_basligi(): void
    {
        $this->assertSame('A (H), B (Ü), C (P)', H::kalemOzeti(['A', null], ['B'], ['C']));
        $this->assertSame('A, B, C +1', H::alacakBasligi(['A', 'B', 'C', 'D', 'A'], null));
        $this->assertSame('not', H::alacakBasligi([], 'not'));
        $this->assertSame('Alacak', H::alacakBasligi([], 'null'));
    }
}
