<?php

namespace Tests\Unit\Domain\Hesabim;

use App\Domain\Hesabim\UyelikKatalogu;
use App\Domain\Hesabim\WhatsappHizmetKarti;
use PHPUnit\Framework\TestCase;

class HesabimDomainTest extends TestCase
{
    private const TEL = '05000000000';

    public function test_uyelik_katalogu(): void
    {
        $this->assertSame('Premium', UyelikKatalogu::paketAdi('3'));
        $this->assertNull(UyelikKatalogu::paketAdi(0));
        $this->assertSame('Yıllık', UyelikKatalogu::periyotAdi(2));
        $this->assertSame('Aylık', UyelikKatalogu::periyotKodAdi('aylik'));
        $this->assertTrue(UyelikKatalogu::premiumMu('3'));
        $this->assertFalse(UyelikKatalogu::premiumMu(2));
    }

    public function test_ucretli_paket_kalan_gun_ve_periyot(): void
    {
        $k = WhatsappHizmetKarti::olustur((object) [
            'whatsapp_paket' => 'pro', 'whatsapp_aktif' => 1, 'whatsapp_paket_bitis' => '2026-11-14',
            'whatsapp_paket_periyot' => 'yillik', 'whatsapp_paket_baslangic' => '2026-01-01', 'whatsapp_paket_deneme' => 0,
        ], '2026-11-04', self::TEL);

        $this->assertTrue($k->aktif);
        $this->assertSame('Yıllık', $k->periyot);
        $this->assertSame(10, $k->kalanGun);
        $this->assertFalse($k->deneme);
    }

    public function test_ucretli_paket_suresi_gecince_kalan_sifir(): void
    {
        $k = WhatsappHizmetKarti::olustur((object) ['whatsapp_paket' => 'premium', 'whatsapp_paket_bitis' => '2026-11-01'], '2026-11-04', self::TEL);

        $this->assertSame(0, $k->kalanGun);
    }

    public function test_ucretsiz_donem_devam_ediyor(): void
    {
        $k = WhatsappHizmetKarti::olustur((object) ['whatsapp_paket' => 'baslangic', 'whatsapp_deneme_bitis' => '2026-11-10'], '2026-11-04', self::TEL);

        $this->assertTrue($k->aktif);
        $this->assertTrue($k->deneme);
        $this->assertSame(6, $k->kalanGun);
        $this->assertSame('2 Ay Ücretsiz', $k->periyot);
    }

    public function test_ucretsiz_donem_bitis_gunu_dahil(): void
    {
        $k = WhatsappHizmetKarti::olustur((object) ['whatsapp_deneme_bitis' => '2026-11-04'], '2026-11-04', self::TEL);

        $this->assertTrue($k->aktif);
        $this->assertSame(0, $k->kalanGun);
    }

    public function test_ucretsiz_donem_dolunca_iletisim_gosterilir(): void
    {
        $k = WhatsappHizmetKarti::olustur((object) ['whatsapp_deneme_bitis' => '2026-11-03'], '2026-11-04', self::TEL);

        $this->assertFalse($k->aktif);
        $this->assertFalse($k->deneme);
        $this->assertStringContainsString(self::TEL, $k->aciklama);
    }

    public function test_deneme_bitisi_yoksa_global_baslangicin_oncesi(): void
    {
        $k = WhatsappHizmetKarti::olustur((object) ['whatsapp_deneme_bitis' => null], '2026-11-04', self::TEL);

        $this->assertSame('2026-08-31', $k->bitis);
        $this->assertFalse($k->aktif);
    }
}
