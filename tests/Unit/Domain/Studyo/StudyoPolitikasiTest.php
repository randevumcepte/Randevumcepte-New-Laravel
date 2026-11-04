<?php

namespace Tests\Unit\Domain\Studyo;

use App\Domain\Studyo\StudyoPolitikasi;
use PHPUnit\Framework\TestCase;

class StudyoPolitikasiTest extends TestCase
{
    public function test_normal_salonda_hicbir_sey_gizlenmez(): void
    {
        $d = (new StudyoPolitikasi(false, true, true))->dizi();
        $this->assertFalse($d['fiyat_gizli']);
        $this->assertSame('tutar', $d['odeme_modu']);
        $this->assertSame(12, $d['yayin_maks_hafta']);
        $this->assertFalse($d['tekrarli_katilim']);
        $this->assertFalse($d['online_rezervasyon']);
        $this->assertSame([], $d['gizli_ozellikler']);
    }

    public function test_studyo_model_b(): void
    {
        $d = (new StudyoPolitikasi(true, true, true))->dizi();
        $this->assertTrue($d['fiyat_gizli']);
        $this->assertSame('ikili', $d['odeme_modu']);
        $this->assertTrue($d['no_show_seans_duser']);
        $this->assertSame(52, $d['yayin_maks_hafta']);
        $this->assertTrue($d['tekrarli_katilim']);
        $this->assertTrue($d['online_rezervasyon']);
        $this->assertContains('prim_hakedis', $d['gizli_ozellikler']);
    }

    public function test_online_rezervasyon_uc_bayrak_ister(): void
    {
        $this->assertFalse((new StudyoPolitikasi(true, true, false))->onlineRezervasyon());
        $this->assertFalse((new StudyoPolitikasi(true, false, true))->onlineRezervasyon());
        $this->assertFalse((new StudyoPolitikasi(true, false, true))->tekrarliKatilim());
    }
}
