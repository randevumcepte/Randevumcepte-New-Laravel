<?php

namespace Tests\Unit\Domain\Paket;

use App\Domain\Paket\PaketTutari;
use PHPUnit\Framework\TestCase;

class PaketTutariTest extends TestCase
{
    public function test_web_paketi_hizmet_degeri_yoksa_paket_duzeyi(): void
    {
        $this->assertSame(['fiyat' => 1500.0, 'seans' => 10], PaketTutari::etkin('1500', 10, [['hizmet_id' => 3], ['seans' => null, 'fiyat' => null]]));
    }

    public function test_mobil_paketi_hizmet_toplami_onceliklidir(): void
    {
        $this->assertSame(['fiyat' => 900.5, 'seans' => 8], PaketTutari::etkin(1000, 6, [['seans' => 5, 'fiyat' => '600.25'], ['seans' => 3, 'fiyat' => 300.25]]));
    }

    public function test_alanlar_bagimsiz_cozulur(): void
    {
        // Fiyat hizmetlerden, seans paket duzeyinden (eski COALESCE(NULLIF(SUM)) ile ayni).
        $this->assertSame(['fiyat' => 400.0, 'seans' => 12], PaketTutari::etkin(999, 12, [['fiyat' => 400, 'seans' => 0]]));
    }

    public function test_hic_deger_yoksa_sifir(): void
    {
        $this->assertSame(['fiyat' => 0.0, 'seans' => 0], PaketTutari::etkin(null, null, []));
    }

    public function test_satis_seansi_bolge_basina_en_buyuk(): void
    {
        // 3 bolgeli 8'er seanslik paket: satista her bolgeye 8 hak (toplam 24 degil).
        $this->assertSame(8, PaketTutari::satisSeansi(24, [['seans' => 8], ['seans' => 8], ['seans' => 8]]));
        $this->assertSame(8, PaketTutari::satisSeansi(16, [['seans' => 8], ['seans' => 5], ['seans' => 3]]));
    }

    public function test_satis_seansi_hizmet_seansi_yoksa_paket_miktari(): void
    {
        $this->assertSame(10, PaketTutari::satisSeansi(10, [['seans' => 0], ['seans' => 0]]));
        $this->assertSame(10, PaketTutari::satisSeansi('10', [['seans' => null]]));
        $this->assertSame(6, PaketTutari::satisSeansi(6, []));
        $this->assertSame(0, PaketTutari::satisSeansi(null, []));
    }

    public function test_seans_metni_esitse_tek_sayi_farkliysa_bolgeler(): void
    {
        $this->assertSame('8', PaketTutari::seansMetni(24, [['seans' => 8], ['seans' => 8], ['seans' => 8]]));
        $this->assertSame('8 / 5 / 3', PaketTutari::seansMetni(16, [['seans' => 8], ['seans' => 5], ['seans' => 3]]));
        $this->assertSame('10', PaketTutari::seansMetni(10, [['seans' => 0]]));
    }
}
