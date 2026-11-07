<?php

namespace Tests\Unit\Domain\Ozet;

use App\Domain\Ozet\MobilOzetHesap;
use PHPUnit\Framework\TestCase;

/** Eski ozetsayfasi ve santral_raporlari formulleri. */
class MobilOzetHesapTest extends TestCase
{
    private function personel(int $rol): object
    {
        return (object) ['id' => 7, 'role_id' => $rol, 'hizmet_prim_yuzde' => 10, 'urun_prim_yuzde' => 20, 'paket_prim_yuzde' => 30];
    }

    private function kalemler(): array
    {
        return [[
            'hizmet' => [(object) ['tutar' => '100.00', 'kalem_personel_id' => 7], (object) ['tutar' => '50.00', 'kalem_personel_id' => 8]],
            'urun'   => [(object) ['tutar' => '40.00', 'personel_id' => 7, 'kalem_personel_id' => 7], (object) ['tutar' => '10.00', 'kalem_personel_id' => 7]],
            'paket'  => [(object) ['tutar' => '200.00', 'kalem_personel_id' => null]],
        ]];
    }

    public function test_yonetici_tum_tutari_toplar_prim_yalniz_kendi_kaleminden(): void
    {
        $t = MobilOzetHesap::tahsilatToplami($this->kalemler(), $this->personel(1));

        $this->assertEqualsWithDelta(400.0, $t['tutar'], 0.001);
        $this->assertEqualsWithDelta(100 * 0.1 + 50 * 0.2, $t['prim'], 0.001);
    }

    public function test_personel_yalniz_kendi_kalemini_gorur_urunde_tahsilat_kolonu_okunur(): void
    {
        $t = MobilOzetHesap::tahsilatToplami($this->kalemler(), $this->personel(5));

        $this->assertEqualsWithDelta(140.0, $t['tutar'], 0.001);
        $this->assertEqualsWithDelta(100 * 0.1 + 40 * 0.2, $t['prim'], 0.001);
    }

    public function test_tutar_turkce_bicimlenir(): void
    {
        $this->assertSame('1.234,50', MobilOzetHesap::tutarMetni(1234.5));
        $this->assertSame('0,00', MobilOzetHesap::tutarMetni(0.0));
    }

    public function test_arama_sayaclari_eski_siniflandirma(): void
    {
        $s = MobilOzetHesap::aramaSayilari([
            ['dcontext' => 'from-internal', 'disposition' => 'ANSWERED'],
            ['dcontext' => 'from-internal', 'disposition' => 'BUSY'],
            ['dcontext' => 'ana-menu', 'disposition' => 'ANSWERED'],
            ['dcontext' => 'yol-tarifi', 'disposition' => 'ANSWERED'],
            ['dcontext' => 'kampanya-tanitimyeni', 'disposition' => 'NO ANSWER'],
            ['dcontext' => 'ext-local', 'disposition' => 'NO ANSWER'],
            ['dcontext' => 'ext-local', 'disposition' => 'ANSWERED'],
            ['dcontext' => 'from-trunk-custom', 'disposition' => 'ANSWERED'],
        ]);

        $this->assertSame(['gelen_arama' => 3, 'giden_arama' => 1, 'cevapsiz_arama' => 2], $s);
    }

    public function test_puan_tek_ondalik_satir_yoksa_sifir(): void
    {
        $this->assertSame(4.3, MobilOzetHesap::puan(13, 3));
        $this->assertEquals(0, MobilOzetHesap::puan(0, 0));
    }
}
