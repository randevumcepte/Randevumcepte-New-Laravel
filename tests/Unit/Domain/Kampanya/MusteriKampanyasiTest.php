<?php

namespace Tests\Unit\Domain\Kampanya;

use App\Domain\Kampanya\MusteriKampanyasi;
use App\Domain\Kampanya\SaatBosluguKampanyasi;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class MusteriKampanyasiTest extends TestCase
{
    public function test_ic_etiket_musteriye_cikmaz_alanlar_eskiyle_ayni(): void
    {
        $r = MusteriKampanyasi::satirdan((object) [
            'id' => 4, 'kampanya_baslik' => 'Sabah Fırsatı - %25 İndirim',
            'kampanya_aciklama' => 'Tüm hizmetlerde %25 indirim.', 'kampanya_fiyat' => '', 'hizmet_normal_fiyat' => '100',
            'kampanya_detay' => 'Not [gap:morning]', 'kampanya_bitis_tarihi' => '2026-11-10 23:59:59',
        ], new DateTimeImmutable('2026-11-07 12:00:00'));

        $this->assertSame(['id', 'baslik', 'aciklama', 'indirim', 'tip', 'fiyat', 'normalFiyat', 'bitisTarihi', 'kalanGun'], array_keys($r));
        $this->assertSame(25, $r['indirim']);
        $this->assertSame('gap', $r['tip']);
        $this->assertNull($r['fiyat']);
        $this->assertSame(100.0, $r['normalFiyat']);
        $this->assertSame('10.11.2026', $r['bitisTarihi']);
        $this->assertSame(3, $r['kalanGun']);
    }

    public function test_olusturma_metinleri_eski_uc_ile_ayni(): void
    {
        $this->assertSame('Sabah Fırsatı - %25 İndirim', SaatBosluguKampanyasi::baslik('Sabah', 25));
        $this->assertSame(
            'Hafta içi sabah saatleri (09:00-12:00) arasında tüm hizmetlerde %25 indirim.',
            SaatBosluguKampanyasi::aciklama('Sabah', 9, 12, 25),
        );
        $this->assertStringEndsWith('Geçerlilik: 01.11.2026 - 08.11.2026. [gap:evening]', SaatBosluguKampanyasi::detay('A.', '01.11.2026', '08.11.2026', 'evening'));
    }

    public function test_saat_bosluga_eslenir_ve_sms_459_karakterde_kesilir(): void
    {
        $this->assertSame('morning', SaatBosluguKampanyasi::saattenAnahtar(11));
        $this->assertSame('afternoon', SaatBosluguKampanyasi::saattenAnahtar(12));
        $this->assertSame('evening', SaatBosluguKampanyasi::saattenAnahtar(17));
        $this->assertSame(459, mb_strlen(SaatBosluguKampanyasi::smsMetni('S', 'B', str_repeat('ş', 600))));
    }
}
