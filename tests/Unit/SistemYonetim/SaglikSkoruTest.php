<?php

namespace Tests\Unit\SistemYonetim;

use App\Domain\SistemYonetim\SaglikSkoru;
use PHPUnit\Framework\TestCase;

class SaglikSkoruTest extends TestCase
{
    private const SIMDI = 1_800_000_000;

    private function gunOnce(int $gun): string
    {
        return date('Y-m-d H:i:s', self::SIMDI - $gun * 86400);
    }

    public function test_tam_aktif_salon_iyi_ve_sebepsiz(): void
    {
        $s = SaglikSkoru::hesapla(['son_giris' => $this->gunOnce(1), 'son_randevu' => $this->gunOnce(1), 'hacim_30g' => 100, 'acik_ticket' => 0, 'sikayet' => 0], true, self::SIMDI);

        $this->assertSame(80, $s['skor']);
        $this->assertSame('iyi', $s['durum']);
        $this->assertSame([], $s['sebepler']);
    }

    public function test_sinyalsiz_salon_kritik_ve_uc_sebep(): void
    {
        $s = SaglikSkoru::hesapla([], false, self::SIMDI);

        $this->assertSame(0, $s['skor']);
        $this->assertSame('kritik', $s['durum']);
        $this->assertSame(['Hesaba 90+ gündür giriş yok', '90+ gündür randevu eklenmemiş', '30 günde hiç randevu yok'], $s['sebepler']);
    }

    public function test_cezalar_tavanli_ve_skor_sifirin_altina_inmez(): void
    {
        $s = SaglikSkoru::hesapla(['son_giris' => $this->gunOnce(1), 'son_randevu' => $this->gunOnce(1), 'hacim_30g' => 5, 'acik_ticket' => 9, 'sikayet' => 9], false, self::SIMDI);

        $this->assertSame(25 + 30 + 6 - 15 - 10, $s['skor']);
        $this->assertContains('9 açık talep var', $s['sebepler']);
        $this->assertSame(0, SaglikSkoru::hesapla(['acik_ticket' => 9, 'sikayet' => 9], false, self::SIMDI)['skor']);
    }

    public function test_esikler(): void
    {
        $orta = SaglikSkoru::hesapla(['son_giris' => $this->gunOnce(20), 'son_randevu' => $this->gunOnce(60), 'hacim_30g' => 3], false, self::SIMDI);

        $this->assertSame(18 + 5 + 2, $orta['skor']);
        $this->assertSame('riskli', $orta['durum']);
        $this->assertSame(['Son giriş 20 gün önce', 'Son randevu 60 gün önce — riskli', '30 günde sadece 3 randevu'], $orta['sebepler']);
        $this->assertSame('kritik', SaglikSkoru::durum(24));
        $this->assertSame('riskli', SaglikSkoru::durum(25));
        $this->assertSame('orta', SaglikSkoru::durum(50));
        $this->assertSame('iyi', SaglikSkoru::durum(75));
    }

    public function test_askida_salon_kritik(): void
    {
        $this->assertSame(['skor' => 0, 'durum' => 'kritik', 'sebepler' => ['Salon askıda: borç']], SaglikSkoru::askida('borç'));
        $this->assertSame(['Salon askıda: sebep yok'], SaglikSkoru::askida(null)['sebepler']);
    }
}
