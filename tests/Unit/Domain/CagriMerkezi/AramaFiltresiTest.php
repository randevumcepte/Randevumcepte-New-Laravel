<?php

namespace Tests\Unit\Domain\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaFiltresi as F;
use PHPUnit\Framework\TestCase;

/** Arama listesi filtresi normalize / snapshot / özet (DB'siz). */
class AramaFiltresiTest extends TestCase
{
    public function test_gecersiz_degerler_atilir(): void
    {
        $f = F::diziden(['kayit' => 'xyz', 'durum' => 'sadik', 'gelmeyen' => '45', 'satis' => 'var', 'cinsiyet' => '0', 'dogumgunu_yaklasan' => '-3', 'kara_liste_haric' => '1', 'whatsapp_onay' => 'false']);
        $this->assertNull($f->kayit);
        $this->assertSame('sadik', $f->durum);
        $this->assertNull($f->gelmeyen);            // yalnız 15/30/60/90
        $this->assertSame(0, $f->cinsiyet);          // 0 (Kadın) boş sayılmaz
        $this->assertNull($f->dogumgunuYaklasan);
        $this->assertTrue($f->bayrak('kara_liste_haric'));
        $this->assertFalse($f->bayrak('whatsapp_onay'));
    }

    public function test_ozel_tarih_yalniz_ozel_kayitta(): void
    {
        $this->assertNull(F::diziden(['kayit' => 'son1yil', 'kayit_t1' => '2026-01-01'])->kayitT1);
        $f = F::diziden(['kayit' => 'ozel', 'kayit_t1' => '2026-01-01', 'kayit_t2' => 'bozuk']);
        $this->assertSame('2026-01-01', $f->kayitT1);
        $this->assertNull($f->kayitT2);
    }

    public function test_snapshot_ozet_ve_aramasiz(): void
    {
        $f = F::diziden(['durum' => 'aktif', 'gelmeyen' => 30, 'cinsiyet' => 1, 'kara_liste_haric' => true, 'ara' => ' ali ']);
        $this->assertSame(['durum' => 'aktif', 'gelmeyen' => 30, 'cinsiyet' => 1, 'kara_liste_haric' => true], $f->snapshot());
        $this->assertSame('Aktif (1-2 işlem) · 30 gündür gelmeyen · Erkek · Kara liste hariç', $f->ozet());
        $this->assertSame('ali', $f->ara);
        $this->assertNull($f->aramasiz()->ara);
        $this->assertSame('aktif', $f->aramasiz()->durum);
        $this->assertSame('Tüm müşteriler', F::diziden([])->ozet());
        $this->assertSame([], F::diziden([])->snapshot());
        $this->assertCount(4, F::secenekler()['bayraklar']);
    }
}
