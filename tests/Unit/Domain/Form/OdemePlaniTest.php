<?php

namespace Tests\Unit\Domain\Form;

use App\Domain\Form\OdemePlani;
use PHPUnit\Framework\TestCase;

/** Sözleşme taksit planı (eski _sozlesmeOdemePlaniUretAPI birebir) — DB YOK. */
class OdemePlaniTest extends TestCase
{
    public function test_esit_bolum_son_taksit_yuvarlama_farki(): void
    {
        $p = OdemePlani::uret(1000, 0, 3, null, '2026-01-15');
        $this->assertSame(333.33, $p['taksit_tutari']);
        $this->assertSame([333.33, 333.33, 333.34], array_column($p['plan'], 'tutar'));
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], array_column($p['plan'], 'tarih'));
    }

    public function test_kapora_dusulur_ve_toplam_korunur(): void
    {
        $p = OdemePlani::uret(1250.50, 250.50, 4);
        $this->assertSame(1000.0, round(array_sum(array_column($p['plan'], 'tutar')), 2));
        $this->assertNull($p['plan'][0]['tarih']);
    }

    public function test_elle_taksit_tutari(): void
    {
        $p = OdemePlani::uret(1000, 0, 3, 300);
        $this->assertSame([300.0, 300.0, 400.0], array_column($p['plan'], 'tutar'));
    }

    public function test_kapora_toplamdan_buyukse_negatif_yok(): void
    {
        $p = OdemePlani::uret(100, 200, 2);
        $this->assertSame([0.0, 0.0], array_column($p['plan'], 'tutar'));
    }
}
