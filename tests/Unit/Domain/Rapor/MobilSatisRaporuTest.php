<?php

namespace Tests\Unit\Domain\Rapor;

use App\Domain\Rapor\MobilSatisRaporu;
use PHPUnit\Framework\TestCase;

/** Mobil satis raporu — eski hizmet/urun/paket/personelRaporlari ile parite — DB YOK. */
class MobilSatisRaporuTest extends TestCase
{
    private function kalem(int $id, int $katalog, $fiyat, array $ek = []): array
    {
        return $ek + ['id' => $id, 'katalog_id' => $katalog, 'fiyat' => $fiyat, 'ad' => 'Kesim', 'randevu_id' => 1, 'seans_sayisi' => 0];
    }

    public function test_seansli_yalniz_randevusuz_ve_seansli(): void
    {
        $this->assertTrue(MobilSatisRaporu::seansliMi(['randevu_id' => null, 'seans_sayisi' => 3]));
        $this->assertFalse(MobilSatisRaporu::seansliMi(['randevu_id' => 8, 'seans_sayisi' => 3]));
        $this->assertFalse(MobilSatisRaporu::seansliMi(['randevu_id' => null, 'seans_sayisi' => 0]));
        $this->assertFalse(MobilSatisRaporu::seansliMi(['randevu_id' => null, 'seans_sayisi' => null]));
    }

    public function test_grup_brut_fiyat_ve_tum_tahsilat(): void
    {
        $sorulan = [];
        $s = MobilSatisRaporu::satirlar(
            [$this->kalem(1, 7, '1000.50'), $this->kalem(2, 7, '200'), $this->kalem(3, 9, '50', ['ad' => null])],
            'hizmet_adi', 'hizmet_id',
            function (array $ids) use (&$sorulan) {
                $sorulan[] = $ids;

                return $ids === [1, 2] ? '700.25' : 0;
            },
        );

        $this->assertSame([[1, 2], [3]], $sorulan, 'ilk gorulme sirasi');
        $this->assertSame(7, $s[0]['id']);
        $this->assertSame(7, $s[0]['hizmet_id']);
        $this->assertSame(2, $s[0]['adet']);
        $this->assertSame('1.200,50', $s[0]['toplam_tutar']);
        $this->assertSame('700,25', $s[0]['toplamKazanc']);
        $this->assertSame('500,25', $s[0]['borc']);
        $this->assertSame(1200.5, $s[0]['toplamTutarNumeric']);
        $this->assertSame('700.25', $s[0]['toplamKazancNumeric']);
        $this->assertSame(500.25, $s[0]['borcNumeric']);
        $this->assertSame('', $s[1]['hizmet_adi']);
    }

    public function test_seansli_paket_satiri_ad_eki_ve_kimlik_alani(): void
    {
        $s = MobilSatisRaporu::satirlar([$this->kalem(1, 7, 10, ['randevu_id' => null, 'seans_sayisi' => 3])], 'paket_adi', 'paket_id', fn () => 0, ' (Seansli)');

        $this->assertSame('Kesim (Seansli)', $s[0]['paket_adi']);
        $this->assertSame(7, $s[0]['paket_id']);
    }

    public function test_personel_raporu_duz_yuzde_ve_seansli_pakete(): void
    {
        $p = ['aktif' => 1, 'personel_adi' => 'Ayşe', 'hizmet_prim_yuzde' => '10', 'urun_prim_yuzde' => '5', 'paket_prim_yuzde' => '20'];
        $pasif = ['aktif' => 0] + $p;
        $tutarlar = ['hizmet:1' => '1000.00', 'hizmet:2' => '500.00', 'paket:4' => '100.00', 'urun:5' => '200.00', 'urun:6' => '300.00'];

        $r = MobilSatisRaporu::personelRaporu(
            [
                ['id' => 1, 'personel_id' => 7, 'personel' => $p, 'randevu_id' => 3, 'seans_sayisi' => 0],
                ['id' => 2, 'personel_id' => 7, 'personel' => $p, 'randevu_id' => null, 'seans_sayisi' => 4],
                ['id' => 3, 'personel_id' => 8, 'personel' => $pasif, 'randevu_id' => 3, 'seans_sayisi' => 0],
                ['id' => 9, 'personel_id' => null, 'personel' => null, 'randevu_id' => 3, 'seans_sayisi' => 0],
            ],
            [['id' => 5, 'personel_id' => 7, 'personel' => $p], ['id' => 6, 'personel_id' => 9, 'personel' => ['personel_adi' => 'Urunlu'] + $p]],
            [['id' => 4, 'personel_id' => 7, 'personel' => $p]],
            fn (string $tur, array $ids) => $tutarlar[$tur.':'.$ids[0]],
        );

        $this->assertCount(2, $r, 'pasif ve personelsiz sayilmaz');
        $this->assertSame('Ayşe', $r[0]['personel_adi']);
        $this->assertSame('1000.00', $r[0]['hizmet_geliri']);
        $this->assertEquals(100, $r[0]['hizmet_primi']);
        $this->assertSame('200.00', $r[0]['urun_geliri']);
        $this->assertEquals(10, $r[0]['urun_primi']);
        $this->assertEquals(600, $r[0]['paket_geliri'], 'paket + seansli hizmet');
        $this->assertEquals(120, $r[0]['paket_primi']);
        $this->assertNull($r[1]['personel_adi'], 'yalniz urun satisi olanda eski cevap adi bos birakir');
        $this->assertSame(0, $r[1]['hizmet_geliri']);
    }
}
