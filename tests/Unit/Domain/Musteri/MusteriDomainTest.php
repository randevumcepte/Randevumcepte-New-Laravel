<?php

namespace Tests\Unit\Domain\Musteri;

use App\Domain\Musteri\DogumTarihi;
use App\Domain\Musteri\MusteriTelefonu;
use App\Domain\Musteri\Segment;
use App\Domain\Musteri\Vki;
use App\Domain\Randevu\RandevuDurumu;
use PHPUnit\Framework\TestCase;

/**
 * Musteri saf kurallari — eski StoreAdminController ile BIREBIR olmasi gerekenleri kilitler
 * (telefon kanonik bicimi duplicate kontrolunun temeli; segment esigi sekme sayilarini belirler).
 */
class MusteriDomainTest extends TestCase
{
    public function test_tr_telefon_kanonik_bicim(): void
    {
        foreach (['0532 123 45 67', '+90 (532) 123-45-67', '905321234567', '00905321234567', '5321234567', "0532\u{00A0}1234567"] as $ham) {
            $this->assertSame('5321234567', MusteriTelefonu::kanonik($ham), $ham);
        }
    }

    public function test_yabanci_telefon_ulke_kodu_korunur(): void
    {
        $this->assertSame('31612345678', MusteriTelefonu::kanonik('+31 6 12345678'));
        $this->assertSame('4915112345678', MusteriTelefonu::kanonik('0049 151 12345678'));
        $this->assertSame('', MusteriTelefonu::kanonik(null));
        $this->assertSame('', MusteriTelefonu::kanonik('—'));
    }

    public function test_arama_varyantlari(): void
    {
        $this->assertSame(['5321234567', '05321234567', '905321234567', '+905321234567', '00905321234567'], MusteriTelefonu::aramaVaryantlari('5321234567'));
        $this->assertSame(['31612345678', '+31612345678', '0031612345678'], MusteriTelefonu::aramaVaryantlari('31612345678'));
        $this->assertSame([], MusteriTelefonu::aramaVaryantlari(''));
    }

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
}
