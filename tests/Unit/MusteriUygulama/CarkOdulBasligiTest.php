<?php

namespace Tests\Unit\MusteriUygulama;

use App\Domain\Cark\CarkOdulBasligi;
use PHPUnit\Framework\TestCase;

class CarkOdulBasligiTest extends TestCase
{
    public function test_puan_her_zaman_miktar_gosterir(): void
    {
        $this->assertSame('50 Puan', CarkOdulBasligi::uret('puan', 50.0, 'Altin', null));
        $this->assertSame('Puan', CarkOdulBasligi::uret('puan', null, null, null));
    }

    public function test_ozel_ada_indirim_eki_eklenir(): void
    {
        $this->assertSame('Kas Alimi · 200₺ İndirim', CarkOdulBasligi::uret('hizmet_indirimi', 200.0, 'Kas Alimi', 'tutar'));
        $this->assertSame('Kas Alimi · %20 İndirim', CarkOdulBasligi::uret('hizmet_indirimi', 20.0, 'Kas Alimi', 'yuzde'));
        $this->assertSame('%20 Sana', CarkOdulBasligi::uret('hizmet_indirimi', 20.0, '%20 Sana', 'yuzde'));
    }

    public function test_adsiz_dilim_otomatik_metin_alir(): void
    {
        $this->assertSame('Bedava Hizmet', CarkOdulBasligi::uret('hizmet_indirimi', 100.0, '', 'yuzde'));
        $this->assertSame('50 ₺ Ürün İndirimi', CarkOdulBasligi::uret('urun_indirimi', 50.0, null, 'tutar'));
        $this->assertSame('Tekrar Dene', CarkOdulBasligi::uret('tekrar_dene', null, null, null));
        $this->assertSame('Boş', CarkOdulBasligi::uret('bos', null, null, null));
    }
}
