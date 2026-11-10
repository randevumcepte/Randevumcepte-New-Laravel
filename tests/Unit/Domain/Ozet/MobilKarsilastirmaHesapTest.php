<?php

namespace Tests\Unit\Domain\Ozet;

use App\Domain\Ozet\MobilKarsilastirmaHesap as Hesap;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** Eski _periodRanges, _offsetRange, _buildHourlyAnalysis davranisi. */
class MobilKarsilastirmaHesapTest extends TestCase
{
    public function test_gecersiz_donem_aylik_olur(): void
    {
        $this->assertSame('aylik', Hesap::donem('x'));
        $this->assertSame('gunluk', Hesap::donem('gunluk'));
    }

    public function test_hafta_pazartesi_baslar_pazar_biter(): void
    {
        [$b, $s, $ob, $os] = Hesap::araliklar('haftalik', Carbon::parse('2026-11-07 12:00:00'));

        $this->assertSame('2026-11-02 00:00:00', $b->toDateTimeString());
        $this->assertSame('2026-11-08 23:59:59', $s->toDateTimeString());
        $this->assertSame('2026-10-26 00:00:00', $ob->toDateTimeString());
        $this->assertSame('2026-11-01 23:59:59', $os->toDateTimeString());
    }

    public function test_seri_adim_kadar_geri_gider(): void
    {
        [$b, $s] = Hesap::geriAralik('aylik', 2, Carbon::parse('2026-11-07'));

        $this->assertSame('2026-09-01', $b->toDateString());
        $this->assertSame('2026-09-30', $s->toDateString());
    }

    public function test_saat_yogunlugu_en_yogun_saate_oranlanir(): void
    {
        $y = Hesap::saatYogunlugu([9 => 2, 10 => 4]);

        $this->assertCount(24, $y);
        $this->assertSame(0.5, $y[9]);
        $this->assertSame(1.0, $y[10]);
        $this->assertSame(0.0, $y[0]);
    }

    public function test_analiz_calisma_saati_seviye_ve_oneri(): void
    {
        $calisma = [(object) ['baslangic_saati' => '10:00:00', 'bitis_saati' => '18:30:00']];
        $a = Hesap::saatAnalizi([10 => 10, 11 => 1], $calisma, [], 'aylik', Carbon::parse('2026-11-07'));

        $this->assertSame(10, $a['workStart']);
        $this->assertSame(19, $a['workEnd']);
        $this->assertSame(10, $a['peakHour']);
        $this->assertSame('prime', $a['hours'][0]['level']);
        $this->assertSame('empty', $a['hours'][1]['level']);
        $this->assertSame('morning', $a['gaps'][0]['key']);
        // Sabah 10-12: (10/10 + 1/10) / 2 = 0.55 -> 'Normal', oneri yok (eski dashboardKarsilastirma esikleri).
        $this->assertSame(0, $a['gaps'][0]['suggestedDiscount']);
        $this->assertSame(40, $a['gaps'][1]['suggestedDiscount']);
        $this->assertSame('Bu Ay', $a['periodLabel']);
    }

    public function test_aktif_kampanya_oneri_metnini_degistirir(): void
    {
        $k = (object) ['id' => 3, 'kampanya_baslik' => 'x', 'kampanya_aciklama' => '%25 indirim', 'kampanya_detay' => '[gap:morning]', 'kampanya_bitis_tarihi' => '2026-11-10 00:00:00'];
        $a = Hesap::saatAnalizi([], [], [$k], 'gunluk', Carbon::parse('2026-11-07 00:00:00'));

        $this->assertSame(9, $a['workStart']);
        $this->assertSame(25, $a['gaps'][0]['activeCampaign']['discount']);
        $this->assertSame(3, $a['gaps'][0]['activeCampaign']['kalanGun']);
        $this->assertSame('Sabah saatleri için %25 indirimli kampanyanız aktif, 3 gün kaldı.', $a['gaps'][0]['message']);
    }
}
