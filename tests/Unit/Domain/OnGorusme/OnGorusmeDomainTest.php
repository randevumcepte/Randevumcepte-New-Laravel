<?php

namespace Tests\Unit\Domain\OnGorusme;

use App\Domain\OnGorusme\GorusmeDurumu;
use App\Domain\OnGorusme\GorusmeNedeni;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OnGorusmeDomainTest extends TestCase
{
    public function test_durum_db_degerleri(): void
    {
        $this->assertSame(GorusmeDurumu::BEKLEMEDE, GorusmeDurumu::dbden(null));
        $this->assertSame(GorusmeDurumu::BEKLEMEDE, GorusmeDurumu::dbden(''));
        $this->assertSame(GorusmeDurumu::SATIS_YAPILDI, GorusmeDurumu::dbden('1'));
        $this->assertSame(GorusmeDurumu::SATIS_YAPILMADI, GorusmeDurumu::dbden(0));
        $this->assertNull(GorusmeDurumu::BEKLEMEDE->db());
        $this->assertSame(0, GorusmeDurumu::SATIS_YAPILMADI->db());
        $this->assertTrue(GorusmeDurumu::BEKLEMEDE->acikMi());
        $this->assertFalse(GorusmeDurumu::SATIS_YAPILDI->acikMi());
        $this->assertNull(GorusmeDurumu::koddan('yok'));
    }

    public function test_neden_uc_istemci_bicimi_ayni_degere_cozulur(): void
    {
        $v2 = GorusmeNedeni::istekten(['neden_tur' => 'urun', 'neden_id' => 5]);
        $mobil = GorusmeNedeni::istekten(['paket_id' => 'null', 'urun_id' => '5', 'hizmet_id' => '']);
        $web = GorusmeNedeni::istekten(['paket_urun' => 'urun-5']);
        foreach ([$v2, $mobil, $web] as $n) {
            $this->assertSame(['urun', 5], [$n->tur, $n->id]);
        }
        $this->assertSame('paket', GorusmeNedeni::istekten(['paket_urun' => '12'])->tur);
        $this->assertSame('hizmet-3', GorusmeNedeni::istekten(['paket_urun' => 'hizmet-3'])->anahtar());
        $this->assertNull(GorusmeNedeni::istekten([]));
    }

    public function test_neden_kolonlari_ve_satir_onceligi(): void
    {
        $this->assertSame(['paket_id' => null, 'urun_id' => null, 'hizmet_id' => 7], GorusmeNedeni::olustur('hizmet', 7)->kolonlar());
        // Eski liste CASE'i: paket > ürün > hizmet
        $this->assertSame('paket', GorusmeNedeni::kolonlardan((object) ['paket_id' => 2, 'urun_id' => 4, 'hizmet_id' => null])->tur);
    }

    public function test_gecersiz_neden(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GorusmeNedeni::olustur('kampanya', 1);
    }
}
