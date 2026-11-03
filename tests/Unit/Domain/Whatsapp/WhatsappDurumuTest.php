<?php

namespace Tests\Unit\Domain\Whatsapp;

use App\Domain\Whatsapp\WhatsappDurumu;
use PHPUnit\Framework\TestCase;

class WhatsappDurumuTest extends TestCase
{
    private function salon(array $alanlar): object
    {
        return (object) ($alanlar + [
            'whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected', 'whatsapp_numara' => '905321234567',
            'whatsapp_kontor' => 0, 'whatsapp_deneme_bitis' => null,
        ]);
    }

    public function test_bagli_icin_aktif_ve_connected_gerekir(): void
    {
        $this->assertTrue(WhatsappDurumu::salondan($this->salon([]), '2026-11-03')->bagli);
        $this->assertFalse(WhatsappDurumu::salondan($this->salon(['whatsapp_aktif' => 0]), '2026-11-03')->bagli);
        $this->assertFalse(WhatsappDurumu::salondan($this->salon(['whatsapp_durum' => 'qr']), '2026-11-03')->bagli);
    }

    public function test_kontorlu_donem_deneme_bitisi_dahil_ucretsiz(): void
    {
        $this->assertFalse(WhatsappDurumu::kontorluDonemMi('2026-11-30', '2026-11-30'));
        $this->assertTrue(WhatsappDurumu::kontorluDonemMi('2026-11-30', '2026-12-01'));
    }

    public function test_kontorlu_donem_bitis_yoksa_global_baslangic(): void
    {
        $this->assertFalse(WhatsappDurumu::kontorluDonemMi(null, '2026-08-31'));
        $this->assertTrue(WhatsappDurumu::kontorluDonemMi('0000-00-00', WhatsappDurumu::KONTOR_BASLANGIC));
    }

    public function test_dizi_kontor_rozeti_ve_kritik(): void
    {
        // Deneme suruyor + bakiye yok → rozet yok
        $d = WhatsappDurumu::salondan($this->salon(['whatsapp_deneme_bitis' => '2026-12-31']), '2026-11-03')->dizi();
        $this->assertNull($d['kontor']);
        $this->assertFalse($d['kontor_kritik']);

        // Kontorlu donem + bakiye bitti → rozet 0 + kritik
        $d = WhatsappDurumu::salondan($this->salon(['whatsapp_deneme_bitis' => '2026-10-01']), '2026-11-03')->dizi();
        $this->assertSame(0, $d['kontor']);
        $this->assertTrue($d['kontor_kritik']);

        // Bagli degil → numara/kontor gizli
        $d = WhatsappDurumu::salondan($this->salon(['whatsapp_durum' => 'disconnected', 'whatsapp_kontor' => 50]), '2026-11-03')->dizi();
        $this->assertSame(['bagli' => false, 'numara' => null, 'kontor' => null, 'kontor_kritik' => false], $d);
    }

    public function test_paylasilan_oturum_salonu(): void
    {
        $this->assertSame(246, WhatsappDurumu::oturumSalonId(416, 246));   // paylaşılan → üst salon
        $this->assertSame(416, WhatsappDurumu::oturumSalonId(416, null));
        $this->assertSame(416, WhatsappDurumu::oturumSalonId(416, 0));
        $this->assertSame(416, WhatsappDurumu::oturumSalonId(416, '416')); // kendini göstermiş
    }
}
