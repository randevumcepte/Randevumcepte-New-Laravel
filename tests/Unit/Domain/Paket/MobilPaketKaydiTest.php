<?php

namespace Tests\Unit\Domain\Paket;

use App\Domain\Paket\MobilPaketKaydi;
use PHPUnit\Framework\TestCase;

/** Eski ApiController::paket_ekle_guncelle + PaketGecerlilik::paketFormunuYaz yazimiyla ayni degerler. */
class MobilPaketKaydiTest extends TestCase
{
    public function test_hizmetsiz_satir_atlanir_bos_seans_fiyat_sifir(): void
    {
        $h = MobilPaketKaydi::hizmetler([
            ['hizmet_id' => '3', 'seans' => '8', 'fiyat' => '100.50'],
            ['hizmet_id' => '4', 'seans' => '', 'fiyat' => ''],
            ['hizmet_id' => '', 'seans' => '1'],
            ['seans' => '1'],
            5,
        ]);

        $this->assertSame([
            ['hizmet_id' => 3, 'seans' => 8, 'fiyat' => 100.5],
            ['hizmet_id' => 4, 'seans' => 0, 'fiyat' => 0],
        ], $h);
        $this->assertSame([], MobilPaketKaydi::hizmetler('x'));
    }

    public function test_paket_duzeyi_dolu_ise_girilen_deger_korunur_etkin_deger_yazilmaz(): void
    {
        $a = MobilPaketKaydi::alanlar([
            'hizmetler' => [['hizmet_id' => 3, 'seans' => 8, 'fiyat' => 100.0], ['hizmet_id' => 4, 'seans' => 8, 'fiyat' => 100.0], ['hizmet_id' => 5, 'seans' => 8, 'fiyat' => 100.0]],
            'miktar'    => '8',
            'fiyat'     => '500.00',
        ]);

        $this->assertSame(500.0, $a['fiyat']);
        $this->assertSame(8, $a['miktar']);
        $this->assertArrayNotHasKey('sure', $a);
        $this->assertArrayNotHasKey('sinirsiz', $a);
    }

    public function test_paket_duzeyi_bos_ise_hizmet_toplami(): void
    {
        $a = MobilPaketKaydi::alanlar([
            'hizmetler' => [['hizmet_id' => 3, 'seans' => 8, 'fiyat' => 100.25], ['hizmet_id' => 4, 'seans' => 2, 'fiyat' => 0]],
            'miktar'    => '',
            'fiyat'     => null,
            'sure'      => '30',
        ]);

        $this->assertSame(100.25, $a['fiyat']);
        $this->assertSame(10, $a['miktar']);
        $this->assertSame('30', $a['sure']);
    }

    public function test_sifir_paket_duzeyi_dolu_sayilir(): void
    {
        $a = MobilPaketKaydi::alanlar(['hizmetler' => [['hizmet_id' => 3, 'seans' => 8, 'fiyat' => 100.0]], 'miktar' => '0', 'fiyat' => '0']);

        $this->assertSame(0.0, $a['fiyat']);
        $this->assertSame(0, $a['miktar']);
    }

    public function test_sinirsiz_sure_bossa_bir_yil_miktar_teknik_tavan(): void
    {
        $a = MobilPaketKaydi::alanlar(['hizmetler' => [], 'miktar' => '5', 'fiyat' => '1', 'sinirsiz' => 1, 'gecerlilik_sayi' => '', 'gecerlilik_birim' => 'ay', 'gunluk_limit' => '']);

        $this->assertSame(1, $a['sinirsiz']);
        $this->assertSame(1, $a['gecerlilik_sayi']);
        $this->assertSame('yil', $a['gecerlilik_birim']);
        $this->assertNull($a['gunluk_limit']);
        $this->assertSame(366, $a['miktar']);
    }

    public function test_sinirsiz_limit_50_ile_sinirli_sure_120_ile_sinirli(): void
    {
        $a = MobilPaketKaydi::alanlar(['hizmetler' => [], 'sinirsiz' => '1', 'gecerlilik_sayi' => '500', 'gecerlilik_birim' => 'ay', 'gunluk_limit' => '99']);

        $this->assertSame(120, $a['gecerlilik_sayi']);
        $this->assertSame(50, $a['gunluk_limit']);
        $this->assertSame(120 * 31 * 50, $a['miktar']);
    }

    public function test_normal_pakette_sure_yazilir_limit_yazilmaz_miktar_degismez(): void
    {
        $a = MobilPaketKaydi::alanlar(['hizmetler' => [], 'miktar' => '6', 'sinirsiz' => 0, 'gecerlilik_sayi' => '2', 'gecerlilik_birim' => 'bilinmeyen', 'gunluk_limit' => '3']);

        $this->assertSame(0, $a['sinirsiz']);
        $this->assertSame(2, $a['gecerlilik_sayi']);
        $this->assertSame('yil', $a['gecerlilik_birim']);
        $this->assertNull($a['gunluk_limit']);
        $this->assertSame(6, $a['miktar']);
    }

    public function test_sure_yoksa_birim_bos_kolon_yoksa_gecerlilik_yazilmaz(): void
    {
        $a = MobilPaketKaydi::alanlar(['hizmetler' => [], 'sinirsiz' => 0, 'gecerlilik_sayi' => '', 'gecerlilik_birim' => 'ay']);
        $this->assertNull($a['gecerlilik_sayi']);
        $this->assertNull($a['gecerlilik_birim']);

        $b = MobilPaketKaydi::alanlar(['hizmetler' => [], 'sinirsiz' => 1], false);
        $this->assertArrayNotHasKey('sinirsiz', $b);
    }
}
