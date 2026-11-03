<?php

namespace Tests\Unit\Domain\Rapor;

use App\Domain\Rapor\PrimHesap;
use PHPUnit\Framework\TestCase;

/**
 * PrimHesap — eski Prim & Hak Edis (primHakedisVerisi) formuluyle parite — DB YOK.
 */
class PrimHesapTest extends TestCase
{
    private function personel(array $ek = []): array
    {
        return array_merge([
            'hizmet_prim_yuzde' => 10, 'urun_prim_yuzde' => 5, 'paket_prim_yuzde' => 20,
            'hizmet_prim_detayli' => 0, 'urun_prim_detayli' => 0, 'paket_prim_detayli' => 0,
        ], $ek);
    }

    private function grup(int $pid, string $tur, int $kalem, float $odenen, bool $seansli = false): array
    {
        return ['personel_id' => $pid, 'tur' => $tur, 'kalem_id' => $kalem, 'seansli' => $seansli, 'odenen' => $odenen];
    }

    public function test_duz_yuzde_tahsilat_uzerinden(): void
    {
        $s = PrimHesap::hesapla([
            $this->grup(7, 'hizmet', 1, 1000), $this->grup(7, 'hizmet', 2, 500),
            $this->grup(7, 'urun', 9, 200),
        ], [7 => $this->personel()], []);

        $this->assertSame(1500.0, $s[7]['hizmet_geliri']);
        $this->assertSame(150.0, $s[7]['hizmet_primi']);
        $this->assertSame(200.0, $s[7]['urun_geliri']);
        $this->assertSame(10.0, $s[7]['urun_primi']);
        $this->assertSame(1700.0, $s[7]['toplam_gelir']);
        $this->assertSame(160.0, $s[7]['toplam_prim']);
    }

    public function test_detayli_oran_kalem_bazli_tanimsiz_kalem_sifir(): void
    {
        $s = PrimHesap::hesapla([
            $this->grup(7, 'hizmet', 1, 1000), $this->grup(7, 'hizmet', 2, 500),
        ], [7 => $this->personel(['hizmet_prim_detayli' => 1])], [7 => ['hizmet' => [1 => 30]]]);

        $this->assertSame(1500.0, $s[7]['hizmet_geliri']);
        $this->assertSame(300.0, $s[7]['hizmet_primi']); // 1000*30% + 500*0%
    }

    public function test_seansli_hizmet_paket_kovasina_duz_paket_yuzdesiyle(): void
    {
        $s = PrimHesap::hesapla([
            $this->grup(7, 'hizmet', 1, 1000, true),
            $this->grup(7, 'paket', 4, 500),
        ], [7 => $this->personel(['hizmet_prim_detayli' => 1])], [7 => ['hizmet' => [1 => 50]]]);

        $this->assertSame(0.0, $s[7]['hizmet_geliri']);
        $this->assertSame(1500.0, $s[7]['paket_geliri']);
        $this->assertSame(300.0, $s[7]['paket_primi']); // (1000 + 500) * 20%; hizmet detayli orani seansliya uygulanmaz
    }

    public function test_listede_olmayan_personel_sayilmaz(): void
    {
        $s = PrimHesap::hesapla([$this->grup(99, 'hizmet', 1, 1000)], [7 => $this->personel()], []);
        $this->assertSame([], $s);
    }
}
