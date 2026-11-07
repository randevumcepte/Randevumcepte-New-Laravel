<?php

namespace Tests\Unit\Domain\Rapor;

use App\Domain\Rapor\MobilKasaHesabi;
use PHPUnit\Framework\TestCase;

/** Mobil kasa hesabi — eski ApiController kasaraporu, gunSonuRaporu, devredenAylar ile parite — DB YOK. */
class MobilKasaHesabiTest extends TestCase
{
    private function tahsilat(array $ek = []): array
    {
        return $ek + ['id' => 1, 'user_id' => 10, 'odeme_tarihi' => '2026-03-02 14:35:00', 'odeme_yontemi_id' => 1, 'para_girisi' => 0,
            'tutar' => '100.50', 'musteri' => 'Ali', 'tahsil_eden' => 'Ayşe', 'yontem' => 'Nakit'];
    }

    private function masraf(array $ek = []): array
    {
        return $ek + ['tutar' => '30', 'odeme_yontemi_id' => 1, 'aciklama' => 'Kira', 'kategori' => 'Genel', 'harcayan_id' => 3,
            'harcayan' => 'Veli', 'yontem' => 'Nakit', 'personel_gideri' => 0, 'personel_maas_odemesi_id' => null];
    }

    public function test_faturali_kisim_tahsilat_basina_toplanir(): void
    {
        $h = MobilKasaHesabi::faturaliKisimlar([
            ['tahsilat_id' => 5, 'tutar' => '10.5'], (object) ['tahsilat_id' => 5, 'tutar' => '2'], ['tahsilat_id' => 6, 'tutar' => 0],
        ]);

        $this->assertSame([5 => 12.5, 6 => 0.0], $h, 'sifir tutarli kalem de anahtar olur: eski liste satiri gosterir');
        $this->assertEquals(12.5, MobilKasaHesabi::faturaliGelir([5, 6, 7], $h));
    }

    public function test_ozet_eski_alanlar_ve_yuvarlama_yok(): void
    {
        $o = MobilKasaHesabi::ozet('100.555', 0, 500, '200.25');

        $this->assertSame('100.555', $o['toplamgelir']);
        $this->assertSame(0, $o['toplamgider']);
        $this->assertSame(100.555, $o['toplamKasa']);
        $this->assertSame(299.75, $o['toplamCiro']);
    }

    public function test_gun_sonu_kova_sayac_ve_ortalama(): void
    {
        $g = MobilKasaHesabi::gunSonu([
            $this->tahsilat(),
            $this->tahsilat(['id' => 2, 'user_id' => 10, 'odeme_yontemi_id' => 2, 'tutar' => '50']),
            $this->tahsilat(['id' => 3, 'user_id' => 11, 'odeme_yontemi_id' => 5, 'tutar' => '9.5', 'musteri' => null]),
            $this->tahsilat(['id' => 4, 'para_girisi' => 1, 'tutar' => '1000']),
        ], [$this->masraf()], false, []);

        $this->assertEquals(1100.5, $g['gelir']['nakit']);
        $this->assertEquals(50, $g['gelir']['kredikarti']);
        $this->assertEquals(9.5, $g['gelir']['diger'], '5=Senet Diger kovasina');
        $this->assertSame(0, $g['gelir']['online']);
        $this->assertSame(3, $g['islem_say'], 'para girisi islem sayilmaz ama gelire girer');
        $this->assertSame(2, $g['musteri_say']);
        $this->assertEqualsWithDelta(160 / 3, $g['ort_sepet'], 0.000001);
        $this->assertEquals(1160, $g['gelir_toplam']);
        $this->assertEquals(30, $g['masraf_toplam']);
        $this->assertEquals(1130, $g['net_toplam']);
        $this->assertEquals(1070.5, $g['net']['nakit']);
        $this->assertSame('14:35', $g['satislar'][0]['saat']);
        $this->assertSame('-', $g['satislar'][2]['musteri']);
        $this->assertSame('Kasa Girişi', $g['satislar'][3]['musteri']);
        $this->assertTrue($g['satislar'][3]['para_girisi']);
    }

    public function test_gun_sonu_faturasiz_gizlemede_faturali_kisim_sayilir(): void
    {
        $g = MobilKasaHesabi::gunSonu([$this->tahsilat(['tutar' => '500'])], [], true, [1 => 120.0]);

        $this->assertEquals(120, $g['gelir']['nakit']);
        $this->assertEquals(120.0, $g['satislar'][0]['tutar']);
    }

    public function test_gun_sonu_masraf_ayrimi_ve_metin(): void
    {
        $g = MobilKasaHesabi::gunSonu([], [
            $this->masraf(['aciklama' => '  ', 'tutar' => '10']),
            $this->masraf(['harcayan_id' => null, 'harcayan' => null, 'tutar' => '20']),
            $this->masraf(['personel_gideri' => 1, 'tutar' => '30']),
            $this->masraf(['personel_maas_odemesi_id' => 9, 'tutar' => '40']),
        ], false, []);

        $this->assertSame('Genel', $g['isletme_masraflari'][0]['aciklama'], 'aciklama bossa kategori');
        $this->assertSame('Kasa', $g['isletme_masraflari'][1]['harcayan']);
        $this->assertSame('Personel Gideri', $g['personel_giderleri'][0]['tip']);
        $this->assertSame('Personel Ödemesi', $g['personel_giderleri'][1]['tip']);
        $this->assertEquals(30, $g['isletme_masraf_toplam']);
        $this->assertEquals(70, $g['personel_gider_toplam']);
    }

    public function test_devreden_ay_araligi_saatsiz_ve_net(): void
    {
        $this->assertSame(['2024-02-01', '2024-02-29'], MobilKasaHesabi::ayAraligi(2024, 2));

        $s = MobilKasaHesabi::aySatiri(2, 'ŞUBAT', 2024, '100.5', 0);
        $this->assertSame('100.5', $s['tahsilatlar']);
        $this->assertSame(100.5, $s['donem_net_kar']);
    }
}
