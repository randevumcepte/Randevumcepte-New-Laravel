<?php

namespace Tests\Unit\Domain\Seans;

use App\Domain\Seans\SeansDokumu;
use PHPUnit\Framework\TestCase;

/** Eski StoreAdminController::seansDokumuPdf ile ayni satirlar ve ozet. */
class SeansDokumuTest extends TestCase
{
    public function test_yalniz_cihaz_verisi_olan_seanslar_satir_uretir_no_siradan_gelir(): void
    {
        $seanslar = [
            ['id' => 1, 'hizmet_id' => 3, 'seans_no' => null, 'seans_tarih' => '2026-01-01'],
            ['id' => 2, 'hizmet_id' => 3, 'seans_no' => null, 'seans_tarih' => '2026-02-01'],
            ['id' => 3, 'hizmet_id' => 4, 'seans_no' => 5, 'seans_tarih' => '2026-03-01'],
            ['id' => 4, 'hizmet_id' => 9, 'seans_no' => 1, 'seans_tarih' => '2026-03-01'],
        ];
        $veriler = [
            2 => [['enerji' => '20', 'hiz' => null, 'ms' => '', 'atis_sayisi' => '100', 'personel_adi' => 'Ece']],
            3 => [['enerji' => null, 'hiz' => '2', 'ms' => '3', 'atis_sayisi' => null, 'personel_adi' => null]],
        ];

        $b = SeansDokumu::bolgeler($seanslar, [3 => 'Bacak'], $veriler);

        $this->assertCount(2, $b);
        $this->assertSame('Bacak', $b[0]['ad']);
        $this->assertSame(['no' => 2, 'tarih' => '01.02.2026', 'enerji' => '20', 'hiz' => '-', 'ms' => '-', 'atis' => '100', 'personel' => 'Ece'], $b[0]['satirlar'][0]);
        $this->assertSame('-', $b[1]['ad']);
        $this->assertSame(5, $b[1]['satirlar'][0]['no']);
    }

    public function test_toplam_paket_carpilir_sinirsiz_metin_hizmet_duz(): void
    {
        $this->assertSame(24, SeansDokumu::toplam(8, true, 3, false));
        $this->assertSame(8, SeansDokumu::toplam(8, true, 0, false));
        $this->assertSame('Sınırsız', SeansDokumu::toplam(8, true, 3, true));
        $this->assertSame(5, SeansDokumu::toplam(5, false, 0, false));
        $this->assertNull(SeansDokumu::toplam(null, false, 0, false));
        $this->assertSame(0, SeansDokumu::toplam(null, true, 3, false));
    }

    public function test_baslangic_bozuksa_ilk_seans_tarihi(): void
    {
        $this->assertSame('15.01.2026', SeansDokumu::baslangic('2026-01-15', ['2025-01-01']));
        $this->assertSame('01.03.2026', SeansDokumu::baslangic('0000-00-00', ['2026-05-01', '2026-03-01', '0000-00-00', null]));
        $this->assertSame('01.03.2026', SeansDokumu::baslangic(null, ['2026-03-01']));
        $this->assertSame('-', SeansDokumu::baslangic(null, []));
    }
}
