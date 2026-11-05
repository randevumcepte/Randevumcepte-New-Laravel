<?php

namespace Tests\Unit\Domain\Paket;

use App\Domain\Paket\PaketGecerlilik;
use PHPUnit\Framework\TestCase;

class PaketGecerlilikTest extends TestCase
{
    public function test_bitis_ilk_gelisten_ay_ve_yil_olarak_hesaplanir(): void
    {
        $this->assertSame('2027-11-05', PaketGecerlilik::bitis('2026-11-05', 1, 'yil'));
        $this->assertSame('2028-11-05', PaketGecerlilik::bitis('2026-11-05', 2, 'yil'));
        $this->assertSame('2027-05-05', PaketGecerlilik::bitis('2026-11-05', 6, 'ay'));
    }

    public function test_ay_sonu_tasmaz(): void
    {
        $this->assertSame('2027-02-28', PaketGecerlilik::bitis('2027-01-31', 1, 'ay'));
        $this->assertSame('2029-02-28', PaketGecerlilik::bitis('2028-02-29', 1, 'yil'));
    }

    public function test_hic_gelinmediyse_veya_sure_yoksa_bitis_yok(): void
    {
        $this->assertNull(PaketGecerlilik::bitis(null, 1, 'yil'));
        $this->assertNull(PaketGecerlilik::bitis('2026-11-05', null, null));
    }

    public function test_bitis_gunu_dahil_gecerli(): void
    {
        $this->assertTrue(PaketGecerlilik::tarihGecerli('2027-11-05', '2027-11-05'));
        $this->assertFalse(PaketGecerlilik::tarihGecerli('2027-11-05', '2027-11-06'));
        $this->assertTrue(PaketGecerlilik::tarihGecerli(null, '2099-01-01'));
    }

    public function test_ust_sinir_sure_boyunca_gunluk_limiti_karsilar(): void
    {
        $this->assertSame(366, PaketGecerlilik::ustSinir(1, 'yil', 1));
        $this->assertSame(732, PaketGecerlilik::ustSinir(1, 'yil', 2));
        $this->assertSame(186, PaketGecerlilik::ustSinir(6, 'ay', 1));
    }

    public function test_sure_metni(): void
    {
        $this->assertSame('2 Yıl', PaketGecerlilik::sureMetni(2, 'yil'));
        $this->assertSame('6 Ay', PaketGecerlilik::sureMetni(6, 'ay'));
        $this->assertSame('', PaketGecerlilik::sureMetni(null, null));
    }
}
