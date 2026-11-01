<?php

namespace Tests\Unit\Domain\Satis;

use App\Domain\Satis\SatirAksiyon;
use PHPUnit\Framework\TestCase;

/** Satış Takibi satır durumu + izinli işlemler (eski web listesi kuralları) — DB YOK. */
class SatirAksiyonTest extends TestCase
{
    private const TAM = ['tahsilat_al' => true, 'adisyon_sil' => true, 'fatura_isaretle' => true];

    public function test_acik_normal_satir_tahsil_et(): void
    {
        $s = SatirAksiyon::hesapla(['kalan_tutar_numeric' => 50, 'odendi' => 0], self::TAM, false);
        $this->assertFalse($s['kapali']);
        $this->assertTrue($s['aksiyonlar']['tahsil_et']);
        $this->assertFalse($s['aksiyonlar']['odeme_bilgisi']);
        $this->assertFalse($s['aksiyonlar']['odeme_al']);
        $this->assertTrue($s['aksiyonlar']['duzenle']);
        $this->assertTrue($s['aksiyonlar']['fatura']);
        $this->assertTrue($s['aksiyonlar']['sil']);
    }

    public function test_kapali_normal_satir_odeme_bilgisi(): void
    {
        $s = SatirAksiyon::hesapla(['kalan_tutar_numeric' => 0], self::TAM, false);
        $this->assertTrue($s['kapali']);
        $this->assertFalse($s['aksiyonlar']['tahsil_et']);
        $this->assertTrue($s['aksiyonlar']['odeme_bilgisi']);
    }

    public function test_studyo_durumu_odendi_bayragindan(): void
    {
        // Stüdyoda kalan 0 olsa da odendi=0 → açık; fatura butonu yok.
        $s = SatirAksiyon::hesapla(['kalan_tutar_numeric' => 0, 'odendi' => 0], self::TAM, true);
        $this->assertFalse($s['kapali']);
        $this->assertTrue($s['aksiyonlar']['odeme_al']);
        $this->assertFalse($s['aksiyonlar']['odeme_geri_al']);
        $this->assertFalse($s['aksiyonlar']['tahsil_et']);
        $this->assertFalse($s['aksiyonlar']['fatura']);

        $k = SatirAksiyon::hesapla(['kalan_tutar_numeric' => 0, 'odendi' => 1], self::TAM, true);
        $this->assertTrue($k['kapali']);
        $this->assertTrue($k['aksiyonlar']['odeme_geri_al']);
    }

    public function test_yetkisiz_kullanici_yazma_butonu_gormez(): void
    {
        $s = SatirAksiyon::hesapla(['kalan_tutar_numeric' => 10], [], false);
        $this->assertFalse($s['aksiyonlar']['tahsil_et']);
        $this->assertFalse($s['aksiyonlar']['fatura']);
        $this->assertFalse($s['aksiyonlar']['sil']);
        $this->assertTrue($s['aksiyonlar']['duzenle']);
    }
}
