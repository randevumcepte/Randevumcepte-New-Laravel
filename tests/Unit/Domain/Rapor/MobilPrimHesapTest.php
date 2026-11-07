<?php

namespace Tests\Unit\Domain\Rapor;

use App\Domain\Rapor\MobilPrimHesap;
use PHPUnit\Framework\TestCase;

/** Mobil prim — eski formatAdisyonFast, primHakedisTopluApi, personelPrimHesaplaAyYil ile parite — DB YOK. */
class MobilPrimHesapTest extends TestCase
{
    private array $p = ['id' => 7, 'personel_adi' => ' Ayşe ', 'hizmet_prim_yuzde' => '10', 'urun_prim_yuzde' => '5', 'paket_prim_yuzde' => '20',
        'hizmet_prim_detayli' => 0, 'urun_prim_detayli' => 0, 'paket_prim_detayli' => 0];

    private function kalem(array $ek = []): array
    {
        return $ek + ['id' => 1, 'katalog_id' => 50, 'fiyat' => '1000', 'indirim_tutari' => '100', 'tahsilat' => 500.0, 'personel_id' => 7,
            'personel' => $this->p, 'ad' => 'Kesim', 'randevu_id' => 3, 'seans_sayisi' => 0];
    }

    private function adisyon(array $hizmet = [], array $urun = [], array $paket = []): array
    {
        return ['id' => 1, 'tarih' => '2026-03-02', 'created_at' => '2026-03-02 14:35:00', 'musteri' => 'Ali', 'hizmetler' => $hizmet, 'urunler' => $urun, 'paketler' => $paket];
    }

    public function test_aralik_gecmis_ay_bu_ay_ve_gun(): void
    {
        $g = MobilPrimHesap::aralik('2', '2024', null, '2026-03-10 11:00:00');
        $this->assertSame('2024-02', $g['donem']);
        $this->assertSame('2024-02-01 00:00:00', $g['baslangic']);
        $this->assertSame('2024-02-29 23:59:59', $g['bitis']);
        $this->assertSame('02', $g['ay']);

        $b = MobilPrimHesap::aralik('03', '2026', null, '2026-03-10 11:00:00');
        $this->assertSame('2026-03-10 11:00:00', $b['bitis'], 'bu ay simdiye kadar');

        $v = MobilPrimHesap::aralik(null, null, null, '2026-03-10 11:00:00');
        $this->assertSame('2026-03', $v['donem']);

        $d = MobilPrimHesap::aralik('01', '2020', '2026-03-09', '2026-03-10 11:00:00');
        $this->assertSame('2026-03', $d['donem']);
        $this->assertSame('2026-03-09 23:59:59', $d['bitis']);
        $this->assertSame('2026-03-09', $d['gun']);

        $bugun = MobilPrimHesap::aralik(null, null, '2026-03-10', '2026-03-10 11:00:00');
        $this->assertSame('2026-03-10 11:00:00', $bugun['bitis']);

        $this->assertNull(MobilPrimHesap::aralik('01', '2020', 'gecersiz', '2026-03-10 11:00:00')['gun']);
    }

    public function test_oran_genel_ve_detayli(): void
    {
        $this->assertSame(10.0, MobilPrimHesap::oran($this->p, 'hizmet', 50, []));
        $d = ['hizmet_prim_detayli' => 1] + $this->p;
        $this->assertSame(0.0, MobilPrimHesap::oran($d, 'hizmet', 50, []), 'detayli ve kalem orani yoksa 0');
        $this->assertSame(33.0, MobilPrimHesap::oran($d, 'hizmet', 50, [7 => ['hizmet' => [50 => 33.0]]]));
        $this->assertSame(0.0, MobilPrimHesap::oran(null, 'hizmet', 50, []));
    }

    public function test_adisyon_net_toplam_brut_alan_ve_hakedis(): void
    {
        $k = MobilPrimHesap::adisyon($this->adisyon(
            [$this->kalem(), $this->kalem(['id' => 2, 'tahsilat' => 100.0, 'randevu_id' => null, 'seans_sayisi' => 4, 'fiyat' => '200', 'indirim_tutari' => null])],
            [$this->kalem(['id' => 3, 'fiyat' => '50', 'indirim_tutari' => 0, 'tahsilat' => 50.0, 'ad' => 'Sampuan'])],
            [$this->kalem(['id' => 4, 'fiyat' => '300', 'indirim_tutari' => 0, 'tahsilat' => 300.0, 'ad' => 'Paket'])],
        ), []);

        $this->assertEquals(1450, $k['toplam_numeric'], 'net: fiyat - indirim');
        $this->assertEquals(950, $k['odenen_numeric']);
        $this->assertSame(500.0, $k['kalan_tutar_numeric']);
        $this->assertSame('1.450,00', $k['toplam']);
        $this->assertEquals(1200.0, $k['hizmetToplam'], 'hizmetToplam brut');
        $this->assertEquals(50, $k['hizmetHakedisTutar']);
        $this->assertEquals(2.5, $k['urunHakedisTutar']);
        $this->assertEquals(80, $k['paketHakedisTutar'], 'seansli hizmet + paket, duz paket yuzdesi');
        $this->assertEquals(132.5, $k['hakedisTutar']);
        $this->assertSame('02.03.2026', $k['acilis_tarihi']);
        $this->assertStringContainsString('Kesim (H)   Ayşe   1.000,00 ₺', $k['icerik']);
        $this->assertStringContainsString('Kesim (Ayşe) (H)', $k['icerikKisaltilmis']);
        $this->assertStringContainsString('(P) (4 Seans)', $k['icerik']);
        $this->assertSame('Ayşe   Ayşe   Ayşe   Ayşe', $k['hizmet_veren']);
    }

    public function test_seansli_detayli_olsa_da_duz_paket_yuzdesi(): void
    {
        $p = ['paket_prim_detayli' => 1, 'hizmet_prim_detayli' => 1] + $this->p;
        $k = MobilPrimHesap::adisyon($this->adisyon([$this->kalem(['randevu_id' => null, 'seans_sayisi' => 2, 'personel' => $p])]), []);

        $this->assertEquals(100, $k['paketHakedisTutar']);
    }

    public function test_personelsiz_kalem_hakedis_uretmez(): void
    {
        $k = MobilPrimHesap::adisyon($this->adisyon([$this->kalem(['personel_id' => null, 'personel' => null])]), []);

        $this->assertSame(0, $k['hizmetHakedisTutar']);
    }

    public function test_personel_kapsami_yalniz_kendi_kalemi(): void
    {
        $a = $this->adisyon([$this->kalem(), $this->kalem(['id' => 2, 'personel_id' => 8])]);

        $this->assertCount(1, MobilPrimHesap::personelKapsami($a, 7)['hizmetler']);
        $this->assertNull(MobilPrimHesap::personelKapsami($a, 9));
    }

    public function test_aylik_cevap_metin_ve_numeric(): void
    {
        $k = MobilPrimHesap::adisyon($this->adisyon([$this->kalem()]), []);
        $c = MobilPrimHesap::aylikCevap([$k, $k], '03', '2026');

        $this->assertSame('2.000,00', $c['hizmet_toplam']);
        $this->assertEquals(2000, $c['hizmet_toplam_numeric']);
        $this->assertSame('100,00', $c['hizmet_hakedis']);
        $this->assertSame('03.2026', $c['donem']);
        $this->assertCount(2, $c['adisyonlar']);
    }

    private function satir(array $h, ?float $gun = null, array $t = []): array
    {
        $personel = $this->p + ['maas' => '1000', 'aktif' => 1, 'takvimde_gorunsun' => 1, 'salon_id' => 20];

        return MobilPrimHesap::hakedisSatiri($personel, $t + ['hizmet_toplam' => 0, 'urun_toplam' => 0, 'paket_toplam' => 0, 'hizmet_hakedis' => 100.0, 'urun_hakedis' => 0, 'paket_hakedis' => 0],
            $h + ['maas' => 0.0, 'prim' => 0.0, 'diger' => 0.0, 'bonus' => 0.0, 'kesinti' => 0.0, 'telafi' => 0.0, 'masraf' => 0.0], $gun);
    }

    public function test_net_borclu_masraf_ve_telafi_dahil(): void
    {
        $this->assertFalse($this->satir([])['borclu']);
        $this->assertTrue($this->satir(['masraf' => 1200.0])['borclu']);
        $this->assertTrue($this->satir(['telafi' => -1101.0])['borclu']);
        $this->assertFalse($this->satir(['telafi' => -1100.0])['borclu']);
        $this->assertSame('7', $this->satir([])['personel_id']);
        $this->assertSame(1000.0, $this->satir([])['maas']);
    }

    public function test_gun_durumlari(): void
    {
        $this->assertSame('bekliyor', MobilPrimHesap::gunDurumu(0, 5));
        $this->assertSame('bekliyor', MobilPrimHesap::gunDurumu(5, 0));
        $this->assertSame('kismi', MobilPrimHesap::gunDurumu(5, 2));
        $this->assertSame('tam', MobilPrimHesap::gunDurumu(5, 5));
        $this->assertSame('fazla', MobilPrimHesap::gunDurumu(5, 6));

        $s = $this->satir([], 30.0);
        $this->assertEquals(100, $s['gun_prim']);
        $this->assertEquals(70, $s['gun_kalan']);
        $this->assertSame('kismi', $s['gun_durum']);
        $this->assertSame(0, $this->satir([], 130.0)['gun_kalan']);
        $this->assertArrayNotHasKey('gun_prim', $this->satir([]));
    }

    public function test_maske_maas_ve_primi_cikarir(): void
    {
        $s = MobilPrimHesap::satirMaskele($this->satir(['masraf' => 2000.0]));

        foreach (['maas', 'hizmet_prim_yuzde', 'hizmet_hakedis', 'odenen_maas', 'bonus', 'kesinti', 'telafi_devri', 'masraf'] as $alan) {
            $this->assertNull($s[$alan], $alan);
        }
        $this->assertFalse($s['borclu']);
        $this->assertSame('7', $s['personel_id']);

        $k = MobilPrimHesap::adisyon($this->adisyon([$this->kalem()]), []);
        $c = MobilPrimHesap::aylikMaskele(MobilPrimHesap::aylikCevap([$k], '03', '2026'));
        $this->assertNull($c['hizmet_hakedis']);
        $this->assertNull($c['hizmet_hakedis_numeric']);
        $this->assertNull($c['adisyonlar'][0]['hakedisTutar']);
        $this->assertSame('900,00', $c['adisyonlar'][0]['toplam']);
    }
}
