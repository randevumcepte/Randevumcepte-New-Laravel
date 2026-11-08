<?php

namespace Tests\Unit;

use App\Domain\Tahsilat\MobilTahsilatHesabi as H;
use PHPUnit\Framework\TestCase;

/**
 * Eski mobil para formullerini kilitler. Beklenen degerler ApiController kodundan elle hesaplandi; kusurlar
 * (vade dagitimindaki 100 kat, her turun odemenin tamamini bolmesi) bilerek test edilir.
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
        $this->assertSame([0, 200.0], H::odemeAyir('100,00', '200,00'));
    }

    public function test_musteri_indirimi_haricisiz_oransal_ve_kalanla_sinirli(): void
    {
        $this->assertSame(50.0, H::musteriIndirimi('150,00', '100,00'));
        $this->assertSame(0.0, H::musteriIndirimi('50,00', '100,00'));
        $this->assertSame(25.0, H::musteriIndirimPayi(100.0, 200.0, 50.0, 0.0));
        $this->assertSame(10.0, H::musteriIndirimPayi(100.0, 200.0, 50.0, 90.0));
        $this->assertSame(0.0, H::musteriIndirimPayi(100.0, 200.0, 50.0, 100.0));
    }

    public function test_harici_indirim_kupon_dusumu_ve_brut_kalan_siniri(): void
    {
        $this->assertSame(70.0, H::kuponDusulmus(100.0, 30.0));
        $this->assertSame(0.0, H::kuponDusulmus(10.0, 30.0));
        $this->assertSame(100.0, H::kuponDusulmus(100.0, 0.0));
        $this->assertSame(100.0, H::uygulanacakIndirim(100.0, 300.0, 50.0, 100.0));
        $this->assertSame(50.0, H::uygulanacakIndirim(100.0, 300.0, 100.0, 150.0));
        $this->assertSame(33.33, H::indirimPayi(100.0, 300.0, 100.0));
    }

    public function test_secili_kalem_payi_yuvarlanmaz_fiyat_sifirda_sifir(): void
    {
        $this->assertEqualsWithDelta(333.3333333, H::kalemPayi('100.00', 300.0, 1000.0), 0.000001);
        $this->assertSame(0.0, (float) H::kalemPayi('0.00', 0, 500.0));
    }

    public function test_vade_payi_eski_kodla_ayni_100_kat_karisimi(): void
    {
        // fiyat "1500.00" → 150000, plan "3000.00" → 300000: onceki ve indirim ham (100 kat olmayan) cikar.
        $this->assertSame(750.0, H::vadeKalemPayi('1500.00', null, 0, '3000.00', '1.500,00'));
        $this->assertSame(747.5, H::vadeKalemPayi('1500.00', null, '500.00', '3000.00', '1.500,00'));
        $this->assertSame(747.0, H::vadeKalemPayi('1500.00', '100.00', '500.00', '3000.00', '1.500,00'));
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

    public function test_on_odeme_paylari_iki_haneye_yuvarlanir(): void
    {
        // Fiyatlar istekteki ham metin: "100.00" → 10000; net = fiyat - kalem basina indirim.
        $this->assertSame([166.67, 333.33], H::onOdemePaylari(['100.00', '200.00'], 0.0, '500,00'));
        $this->assertSame([166.39, 333.61], H::onOdemePaylari(['100.00', '200.00'], 50.0, '500,00'));
        // Hizmet 100 + urun 50 + paket 150 tek listede: paylar odemeyi bir kez boler.
        $this->assertSame([100.0, 50.0, 150.0], H::onOdemePaylari(['100,00', '50,00', '150,00'], 0.0, '300,00'));
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
        $this->assertSame(0.0, H::hariciFiyat('-5'));
        $this->assertSame(1.0, H::hariciAdet('0'));
        $this->assertSame(300.0, H::hariciTahsilatTutari('500', 300.0));
        $this->assertSame(0.0, H::hariciTahsilatTutari('500', 0.0));
        $this->assertSame(100.0, H::hariciPay(100.0, 300.0, 300.0));
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
