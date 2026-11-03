<?php

namespace Tests\Unit\Domain\Musteri;

use App\Domain\Musteri\DogumTarihi;
use App\Domain\Musteri\Segment;
use App\Domain\Musteri\Vki;
use App\Domain\Randevu\RandevuDurumu;
use PHPUnit\Framework\TestCase;

/**
 * Musteri saf kurallari — eski StoreAdminController ile BIREBIR olmasi gerekenleri kilitler
 * (segment esigi sekme sayilarini belirler). Telefon kurali: Tests\Unit\Domain\TelefonNumarasiTest.
 */
class MusteriDomainTest extends TestCase
{
    public function test_segment_liste_kurali(): void
    {
        $this->assertSame(Segment::PASIF, Segment::tahsilatSayisindan(0));
        $this->assertSame(Segment::AKTIF, Segment::tahsilatSayisindan(1));
        $this->assertSame(Segment::AKTIF, Segment::tahsilatSayisindan(2));
        $this->assertSame(Segment::SADIK, Segment::tahsilatSayisindan(3));   // >=3 (detay basligindaki >3 degil)
        $this->assertSame(['kod' => Segment::SADIK, 'etiket' => 'Sadık'], Segment::ozet(7));
    }

    public function test_segment_filtre_ve_sql(): void
    {
        $this->assertSame(Segment::TUMU, Segment::filtre('abc'));
        $this->assertSame(Segment::TUMU, Segment::filtre(9));
        $this->assertSame(Segment::YENI, Segment::filtre('4'));
        $this->assertSame('c = 0', Segment::sqlKosulu(Segment::PASIF, 'c'));
        $this->assertSame('c BETWEEN 1 AND 2', Segment::sqlKosulu(Segment::AKTIF, 'c'));
        $this->assertSame('c >= 3', Segment::sqlKosulu(Segment::SADIK, 'c'));
        $this->assertNull(Segment::sqlKosulu(Segment::TUMU, 'c'));
    }

    public function test_dogum_tarihi(): void
    {
        $this->assertSame('1990-05-07', DogumTarihi::coz('1990-05-07'));
        $this->assertNull(DogumTarihi::coz('1990-02-31'));
        $this->assertSame('1985-03-09', DogumTarihi::coz(null, '9', '3', '1985'));
        $this->assertSame('1900-03-09', DogumTarihi::coz(null, 9, 3, null));  // yilsiz → 1900
        $this->assertNull(DogumTarihi::coz(null, null, 3, 1985));             // gun yok
        $this->assertSame(['gun' => 9, 'ay' => 3, 'yil' => null], DogumTarihi::parcalar('1900-03-09'));
        $this->assertSame(['gun' => null, 'ay' => null, 'yil' => null], DogumTarihi::parcalar(null));
    }

    public function test_vki(): void
    {
        $this->assertSame(23.53, Vki::hesapla(170, 68));
        $this->assertNull(Vki::hesapla(0, 68));
        $this->assertSame('Normal', Vki::sinif(23.53));
        $this->assertSame('Zayıf', Vki::sinif(17));
        $this->assertSame('Obez', Vki::sinif(31));
        $this->assertSame('', Vki::sinif(null));
    }

    public function test_randevu_durum_etiketi(): void
    {
        $this->assertSame('geldi', RandevuDurumu::etiket(1, 1)['kod']);
        $this->assertSame('gelmedi', RandevuDurumu::etiket('1', '0')['kod']);
        $this->assertSame('onayli', RandevuDurumu::etiket(1, null)['kod']);
        $this->assertSame('iptal', RandevuDurumu::etiket(2, null)['kod']);
        $this->assertSame('musteri_iptal', RandevuDurumu::etiket(3, null)['kod']);
        $this->assertSame('beklemede', RandevuDurumu::etiket(0, null)['kod']);
    }

    public function test_randevu_liste_aksiyonlari_eski_menu_ile_ayni(): void
    {
        $this->assertSame(['onayla' => true, 'duzenle' => true, 'iptal' => true], RandevuDurumu::aksiyonlar(0, null, true, true));
        $this->assertSame(['onayla' => false, 'duzenle' => true, 'iptal' => true], RandevuDurumu::aksiyonlar(0, null, false, true));
        $this->assertSame(['onayla' => false, 'duzenle' => true, 'iptal' => true], RandevuDurumu::aksiyonlar(1, null, true, true));
        $this->assertSame(['onayla' => false, 'duzenle' => true, 'iptal' => true], RandevuDurumu::aksiyonlar('1', '0', true, true));
        $this->assertSame(['onayla' => false, 'duzenle' => false, 'iptal' => false], RandevuDurumu::aksiyonlar(1, 1, true, true));
        $this->assertSame(['onayla' => false, 'duzenle' => true, 'iptal' => false], RandevuDurumu::aksiyonlar(2, null, true, true));
        $this->assertSame(['onayla' => true, 'duzenle' => false, 'iptal' => false], RandevuDurumu::aksiyonlar(0, null, true, false));
    }
}
