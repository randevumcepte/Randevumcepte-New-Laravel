<?php

namespace Tests\Unit\Domain\Bildirim;

use App\Domain\Bildirim\DogalTarih;
use App\Domain\Bildirim\SifreMaskesi;
use PHPUnit\Framework\TestCase;

class BildirimMetinleriTest extends TestCase
{
    public function test_dogal_tarih_bugun_yarin_ve_tarih(): void
    {
        $this->assertSame('bugün saat 09:05', DogalTarih::ifade('2026-11-08 09:05', '2026-11-08'));
        $this->assertSame('yarın saat 10:30', DogalTarih::ifade('2026-11-09 10:30:00', '2026-11-08'));
        $this->assertSame('19 Mayıs Salı günü saat 10:30', DogalTarih::ifade('2099-05-19 10:30:00', '2026-11-08'));
    }

    public function test_sifre_maskesi_turkce_buyuk_harf_dahil_yalniz_sifreyi_gizler(): void
    {
        $this->assertSame('yeni şifreniz : ***** olarak güncellenmiştir.', SifreMaskesi::uygula('yeni şifreniz : AB12CD olarak güncellenmiştir.'));
        $this->assertSame('adınız:5321234567 Şifreniz:***** Uygulamamızı', SifreMaskesi::uygula('adınız:5321234567 Şifreniz:aB3xY Uygulamamızı'));
        $this->assertSame('ŞİFRENİZ: *****.', SifreMaskesi::uygula('ŞİFRENİZ: 1234.'));
        $this->assertSame('Şifreniz değiştirildi', SifreMaskesi::uygula('Şifreniz değiştirildi'));
        $this->assertSame('x şifreniz : *****', SifreMaskesi::uygula(SifreMaskesi::uygula('x şifreniz : AB12CD')));
    }
}
