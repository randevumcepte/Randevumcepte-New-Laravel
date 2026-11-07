<?php

namespace Tests\Unit\Domain\Karsilastirma;

use App\Domain\Karsilastirma\AlanKarsilastirici as K;
use PHPUnit\Framework\TestCase;

class AlanKarsilastiriciTest extends TestCase
{
    public function test_sayi_ve_metin_toleransla_esittir(): void
    {
        $this->assertTrue(K::esitMi('1500.00', 1500));
        $this->assertTrue(K::esitMi(0.1 + 0.2, 0.3));
        $this->assertFalse(K::esitMi(100.01, 100.02));
        $this->assertTrue(K::esitMi('1.234,50', '1.234,50'));
        $this->assertFalse(K::esitMi('1.234,50', '1.234,51'));
    }

    public function test_bastaki_sifir_sayi_sayilmaz_ve_mantiksal_deger_sayiya_esit_degildir(): void
    {
        $this->assertFalse(K::esitMi('05321234567', '5321234567'));
        $this->assertFalse(K::esitMi(true, 1));
    }

    public function test_olmayan_alan_null_ile_aynidir_ama_bos_metinle_degildir(): void
    {
        $this->assertSame([], K::farklar(['a' => null], [], ['a']));
        $this->assertSame(['a: eski null yeni ""'], K::farklar(['a' => null], ['a' => ''], ['a']));
    }

    public function test_tutar_farki_ic_ice_satirda_yazilir(): void
    {
        $eski = ['data' => [['id' => 1, 'kalem' => [['tutar' => '10.00']]]]];
        $yeni = ['data' => [['id' => 1, 'kalem' => [['tutar' => '10.50']]]]];

        $this->assertSame(['data[0].kalem[0].tutar: eski "10.00" yeni "10.50"'], K::farklar($eski, $yeni, ['data[].id', 'data[].kalem[].tutar']));
    }

    public function test_satir_sayisi_farki_bir_kez_yazilir(): void
    {
        $farklar = K::farklar(['data' => [['id' => 1], ['id' => 2]]], ['data' => [['id' => 1]]], ['data[].id', 'data[].ad']);

        $this->assertSame(['data[]: satir sayisi eski 2 yeni 1'], $farklar);
    }

    public function test_anahtarla_eslesen_liste_sira_farkini_saymaz(): void
    {
        $eski = [['id' => 1, 'adet' => 2], ['id' => 2, 'adet' => 3]];
        $yeni = [['id' => 2, 'adet' => 3], ['id' => 1, 'adet' => 2]];

        $this->assertSame([], K::farklar($eski, $yeni, ['[].adet'], ['' => ['id']]));
        $this->assertSame(['[0].adet: eski 2 yeni 3', '[1].adet: eski 3 yeni 2'], K::farklar($eski, $yeni, ['[].adet']));
    }

    public function test_anahtarla_eslesen_listede_eksik_satir_ve_tutar_farki_yazilir(): void
    {
        $eski = [['id' => 1, 'adet' => 2], ['id' => 2, 'adet' => 3]];
        $yeni = [['id' => 1, 'adet' => 5], ['id' => 3, 'adet' => 3]];

        $this->assertSame(
            ['[2]: yalniz eskide var', '[3]: yalniz yenide var', '[1].adet: eski 2 yeni 5'],
            K::farklar($eski, $yeni, ['[].adet'], ['' => ['id']]),
        );
    }

    public function test_ayni_anahtarli_satirlar_ezilmez(): void
    {
        $eski = [['ad' => null, 'p' => 1], ['ad' => null, 'p' => 2]];
        $yeni = [['ad' => null, 'p' => 1], ['ad' => null, 'p' => 9]];

        $this->assertSame(['[#2].p: eski 2 yeni 9'], K::farklar($eski, $yeni, ['[].p'], ['' => ['ad']]));
    }

    public function test_farkli_adli_alan_ve_govdenin_kendisi_eslesir(): void
    {
        $this->assertSame([], K::farklar(1, ['tur' => '1'], [['$', 'tur']]));
        $this->assertSame(['$ = tur: eski 1 yeni 2'], K::farklar(1, ['tur' => 2], [['$', 'tur']]));
    }

    public function test_dizi_donen_alan_derin_karsilastirilir(): void
    {
        $this->assertSame([], K::farklar(['g' => ['n' => '1.0', 'k' => [1, 2]]], ['g' => ['k' => [1, 2], 'n' => 1]], ['g']));
        $this->assertSame(['g: eski {"k":[1,2]} yeni {"k":[1,3]}'], K::farklar(['g' => ['k' => [1, 2]]], ['g' => ['k' => [1, 3]]], ['g']));
    }

    public function test_liste_beklenen_yerde_baska_deger_fark_sayilir(): void
    {
        $this->assertSame(['data[]: liste degil, eski "hata" yeni []'], K::farklar(['data' => 'hata'], ['data' => []], ['data[].id']));
    }
}
