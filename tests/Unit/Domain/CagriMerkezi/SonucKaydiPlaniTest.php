<?php

namespace Tests\Unit\Domain\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\SonucKaydiPlani as P;
use PHPUnit\Framework\TestCase;

/**
 * "Sonucu Kaydet" karar mantığı (DB'siz) — eski santral_not_ekle ile BİREBİR davranış.
 */
class SonucKaydiPlaniTest extends TestCase
{
    private const SIMDI = '2026-11-03 15:00:00';

    private function kayit(?int $durum = null, ?string $tarih = null, ?string $saat = null, ?string $tamamlandi = null): array
    {
        return ['durum' => $durum, 'tarih' => $tarih, 'saat' => $saat, 'ar_tamamlandi_at' => $tamamlandi];
    }

    public function test_bos_istek_reddedilir_not_veya_sonuc_yeter(): void
    {
        $this->assertTrue(P::bosMu(['sonuc' => null, 'not' => '  ', 'tarih' => null, 'saat' => null]));
        $this->assertTrue(P::bosMu(['sonuc' => 3]));                       // 3 agent'ın doğrudan seçebileceği sonuç değil
        $this->assertTrue(P::bosMu(['tarih' => '2026-11-04']));             // saat yok → zaman yok
        $this->assertFalse(P::bosMu(['not' => 'aradı, düşünecek']));
        $this->assertFalse(P::bosMu(['sonuc' => 0]));                       // 0 (Ulaşılamadı) boş sayılmaz
        $this->assertFalse(P::bosMu(['tarih' => '2026-11-04', 'saat' => '10:00']));
    }

    public function test_acik_sonuc_oncelikli_tarih_verilse_bile(): void
    {
        $p = P::olustur($this->kayit(), ['sonuc' => 6, 'tarih' => '2026-11-05', 'saat' => '11:00'], self::SIMDI);
        $this->assertSame(6, $p->durum);
        $this->assertFalse($p->yeniAramaRandevusu);
        $this->assertSame('2026-11-05', $p->kolonlar['tarih']);
    }

    public function test_zaman_verilince_tekrar_aranacak_ve_hatirlatma_dongusu_sifirlanir(): void
    {
        $p = P::olustur($this->kayit(2), ['tarih' => '2026-11-05', 'saat' => '11:00', 'not' => 'akşam ara'], self::SIMDI);
        $this->assertSame(3, $p->durum);
        $this->assertTrue($p->yeniAramaRandevusu);
        $this->assertSame(['durum' => 3, 'son_arama_zamani' => self::SIMDI, 'musteri_not' => 'akşam ara', 'tarih' => '2026-11-05', 'saat' => '11:00',
            'ar_5dk_at' => null, 'ar_zaman_at' => null, 'ar_gecikti' => 0, 'ar_tamamlandi_at' => null], $p->kolonlar);
    }

    public function test_yalniz_not_hic_aranmamissa_gorusuldu_aranmissa_durum_korunur(): void
    {
        $this->assertSame(4, P::olustur($this->kayit(), ['not' => 'x'], self::SIMDI)->durum);
        $this->assertSame(5, P::olustur($this->kayit(5), ['not' => 'x'], self::SIMDI)->durum);
    }

    public function test_bos_not_mevcut_notu_ezmez(): void
    {
        $p = P::olustur($this->kayit(), ['sonuc' => 6, 'not' => ''], self::SIMDI);
        $this->assertArrayNotHasKey('musteri_not', $p->kolonlar);
    }

    public function test_takvimdeki_kayit_sonucla_tamamlanir_zaman_korunur(): void
    {
        // Bekleyen arama randevusu (3 + zaman) "Görüşüldü" ile işaretlenince takvimden düşmez.
        $p = P::olustur($this->kayit(3, '2026-11-03', '14:00'), ['sonuc' => 4], self::SIMDI);
        $this->assertSame(4, $p->durum);
        $this->assertArrayNotHasKey('tarih', $p->kolonlar);                 // zaman KORUNUR
        $this->assertSame(self::SIMDI, $p->kolonlar['ar_tamamlandi_at']);
        $this->assertSame(0, $p->kolonlar['ar_gecikti']);
    }

    public function test_takvimdeki_kayit_yine_takvim_sonucuysa_tamamlanmaz(): void
    {
        $p = P::olustur($this->kayit(3, '2026-11-03', '14:00'), ['sonuc' => 7, 'satis_tutari' => '1500.456'], self::SIMDI);
        $this->assertSame(7, $p->durum);
        $this->assertArrayNotHasKey('ar_tamamlandi_at', $p->kolonlar);
        $this->assertSame(1500.46, $p->satisTutari);
    }

    public function test_zamansiz_kayitta_zaman_temizlenir_tamamlanmis_kayit_tekrar_damgalanmaz(): void
    {
        $p = P::olustur($this->kayit(null, '2026-11-01', ''), ['sonuc' => 2], self::SIMDI);
        $this->assertNull($p->kolonlar['tarih']);
        $this->assertNull($p->kolonlar['saat']);
        $q = P::olustur($this->kayit(3, '2026-11-03', '14:00', '2026-11-03 14:02:00'), ['sonuc' => 4], self::SIMDI);
        $this->assertArrayNotHasKey('ar_tamamlandi_at', $q->kolonlar);
        $this->assertNull(P::olustur($this->kayit(), ['sonuc' => 4, 'satis_tutari' => 99], self::SIMDI)->satisTutari);   // tutar yalnız 7'de
    }

    public function test_tamamla_ve_arama_basladi(): void
    {
        $this->assertSame(['durum' => 8, 'ar_gecikti' => 0, 'ar_tamamlandi_at' => '2026-11-01 10:00:00', 'son_arama_zamani' => self::SIMDI],
            P::tamamla(AramaSonucu::RANDEVU_OLUSTURULDU, '2026-11-01 10:00:00', self::SIMDI));
        $this->assertSame(['durum' => 9, 'ar_gecikti' => 0, 'ar_tamamlandi_at' => self::SIMDI], P::tamamla(AramaSonucu::IPTAL, null, self::SIMDI));
        $this->assertSame(['son_arama_zamani' => self::SIMDI, 'durum' => 1, 'ar_tamamlandi_at' => self::SIMDI, 'ar_gecikti' => 0], P::aramaBasladi(3, self::SIMDI));
        $this->assertSame(['son_arama_zamani' => self::SIMDI], P::aramaBasladi(4, self::SIMDI));
        $this->assertSame(3, P::aramaRandevusu('2026-11-05', '09:30')['durum']);
    }
}
