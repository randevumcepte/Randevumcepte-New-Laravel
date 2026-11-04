<?php

namespace Tests\Unit\Domain\Ders;

use App\Domain\Ders\DersMesaji;
use App\Domain\Ders\DersRaporu;
use App\Domain\Ders\KatilimciDurumu;
use App\Domain\Ders\SeansEtkisi;
use App\Domain\Ders\YayinAraligi;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DersKurallariTest extends TestCase
{
    public function test_kontenjan_iptal_ve_beklemeyi_saymaz(): void
    {
        $this->assertFalse(KatilimciDurumu::IPTAL->kontenjanTutar());
        $this->assertFalse(KatilimciDurumu::BEKLEME->kontenjanTutar());
        $this->assertTrue(KatilimciDurumu::TELAFI->kontenjanTutar());
        $this->assertTrue(KatilimciDurumu::GELMEDI->kontenjanTutar());
    }

    public function test_kapasite_dolunca_yeni_kayit_beklemeye_duser(): void
    {
        $this->assertSame(KatilimciDurumu::REZERVE, KatilimciDurumu::yeniKayit(2, 3));
        $this->assertSame(KatilimciDurumu::BEKLEME, KatilimciDurumu::yeniKayit(3, 3));
    }

    public function test_seans_etkisi_durum_ve_moda_gore(): void
    {
        $this->assertSame(1, SeansEtkisi::geldiDegeri(KatilimciDurumu::GELDI, false));
        $this->assertSame(2, SeansEtkisi::geldiDegeri(KatilimciDurumu::TELAFI, false));
        $this->assertSame(0, SeansEtkisi::geldiDegeri(KatilimciDurumu::GELMEDI, true), 'stüdyoda no-show seans yakar, geldi=0 yazilir');
        $this->assertNull(SeansEtkisi::geldiDegeri(KatilimciDurumu::GELMEDI, false), 'normalde gelmedi iadedir');
        $this->assertNull(SeansEtkisi::geldiDegeri(KatilimciDurumu::REZERVE, true));
    }

    public function test_yayin_gecmis_gunu_uretmez_ve_haftaya_uyar(): void
    {
        // 2026-11-04 Carsamba. Pazartesi (1) o hafta gecmiste kalir.
        $a = YayinAraligi::olustur('2026-11-04', null, 2, false);
        $this->assertSame(['2026-11-09'], $a->tarihler(1));
        $this->assertSame(['2026-11-04', '2026-11-11'], $a->tarihler(3));
    }

    public function test_yayin_hafta_tavani_moda_gore(): void
    {
        $this->assertSame(12, YayinAraligi::olustur('2026-11-04', null, 40, false)->hafta);
        $this->assertSame(40, YayinAraligi::olustur('2026-11-04', null, 40, true)->hafta);
        $this->assertSame(52, YayinAraligi::olustur('2026-11-04', null, 99, true)->hafta);
        $this->assertSame(4, YayinAraligi::olustur('2026-11-04', null, null, false)->hafta);
    }

    public function test_bitis_yalniz_studyoda_gecerli_ve_asilmaz(): void
    {
        $studyo = YayinAraligi::olustur('2026-11-04', '2026-11-18', null, true);
        $this->assertSame(['2026-11-04', '2026-11-11', '2026-11-18'], $studyo->tarihler(3));
        $this->assertSame(['2026-11-09', '2026-11-16'], $studyo->tarihler(1));

        $normal = YayinAraligi::olustur('2026-11-04', '2026-11-18', 1, false);
        $this->assertSame(['2026-11-04'], $normal->tarihler(3), 'normal modda bitis yok sayilir');
    }

    public function test_bitis_baslangictan_once_olamaz(): void
    {
        $this->expectException(InvalidArgumentException::class);
        YayinAraligi::olustur('2026-11-04', '2026-11-01', null, true);
    }

    public function test_rapor_oranlari_ve_siralama(): void
    {
        $oturumlar = [
            (object) ['id' => 1, 'kapasite' => 4, 'personel_id' => 7, 'ders_tipi' => 'Mat'],
            (object) ['id' => 2, 'kapasite' => 2, 'personel_id' => 8, 'ders_tipi' => null],
        ];
        $durumlar = [
            1 => ['geldi', 'gelmedi', 'bekleme', 'iptal'],
            2 => ['geldi', 'telafi'],
        ];
        $r = DersRaporu::hesapla($oturumlar, $durumlar, [7 => 'Ayşe', 8 => 'Can']);

        $this->assertSame(2, $r['ozet']['oturum']);
        $this->assertSame(6, $r['ozet']['kapasite']);
        $this->assertSame(4, $r['ozet']['katilim'], 'bekleme ve iptal katilim sayilmaz');
        $this->assertSame(2, $r['ozet']['gelen'], 'telafi gelen sayilmaz');
        $this->assertSame(1, $r['ozet']['bekleme']);
        $this->assertSame(67, $r['ozet']['doluluk']);
        $this->assertSame(33, $r['ozet']['noshow']);
        $this->assertContains('Grup Dersi', array_column($r['ders'], 'ders_tipi'), 'ders adi bossa varsayilan');
        $this->assertSame(100, array_column($r['egitmen'], 'doluluk', 'personel')['Can']);
    }

    public function test_bos_rapor_sifira_bolmez(): void
    {
        $r = DersRaporu::hesapla([], [], []);
        $this->assertSame(0, $r['ozet']['doluluk']);
        $this->assertSame(0, $r['ozet']['noshow']);
        $this->assertSame([], $r['egitmen']);
    }

    public function test_mesaj_tarih_saat_ve_varsayilan_ders(): void
    {
        $this->assertSame(
            'Sayın Ali; 09.11.2026 saat 09:30 Grup Dersi dersiniz iptal edilmiştir. Bilginize.',
            DersMesaji::iptal('Ali', '2026-11-09', '09:30:00', null),
        );
    }

    public function test_kalan_uyari_esik(): void
    {
        $this->assertNull(DersMesaji::kalanUyari('Ali', 'Mat', 4));
        $this->assertStringContainsString('3 dersiniz kaldı', DersMesaji::kalanUyari('Ali', 'Mat', 3));
        $this->assertStringContainsString('hakkınız doldu', DersMesaji::kalanUyari('Ali', 'Mat', 0));
    }
}
