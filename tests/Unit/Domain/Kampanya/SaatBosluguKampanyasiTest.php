<?php

namespace Tests\Unit\Domain\Kampanya;

use App\Domain\Kampanya\SaatBosluguKampanyasi;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SaatBosluguKampanyasiTest extends TestCase
{
    private function satir(array $ek = []): object
    {
        return (object) ($ek + [
            'id' => 7,
            'kampanya_baslik' => 'Sabah Fırsatı - %25 İndirim',
            'kampanya_aciklama' => 'Hafta içi sabah saatleri (10:00-12:00) arasında tüm hizmetlerde %25 indirim.',
            'kampanya_detay' => 'Otomatik öneri. [gap:morning]',
            'kampanya_bitis_tarihi' => '2026-11-10 23:59:59',
        ]);
    }

    public function test_alanlar_eski_uc_ile_ayni_okunur(): void
    {
        $k = SaatBosluguKampanyasi::satirdan($this->satir(), new DateTimeImmutable('2026-11-07 12:00:00'));

        $this->assertSame('morning', $k['gapKey']);
        $this->assertSame('Sabah', $k['gapLabel']);
        $this->assertSame(10, $k['startHour']);
        $this->assertSame(12, $k['endHour']);
        $this->assertSame(25, $k['discount']);
        $this->assertSame(3, $k['kalanGun']);
        $this->assertSame(1, $k['order']);
    }

    public function test_metin_yoksa_varsayilan_saat_ve_basliktaki_indirim(): void
    {
        $k = SaatBosluguKampanyasi::satirdan(
            $this->satir(['kampanya_aciklama' => '', 'kampanya_detay' => '[gap:evening]', 'kampanya_baslik' => 'Akşam %30']),
            new DateTimeImmutable('2026-11-10 23:00:00'),
        );

        $this->assertSame(17, $k['startHour']);
        $this->assertSame(20, $k['endHour']);
        $this->assertSame(30, $k['discount']);
        $this->assertSame(0, $k['kalanGun']);
    }

    public function test_bilinmeyen_etiket_atlanir(): void
    {
        $simdi = new DateTimeImmutable('2026-11-07 12:00:00');

        $this->assertNull(SaatBosluguKampanyasi::satirdan($this->satir(['kampanya_detay' => '[gap:gece]']), $simdi));
        $this->assertNull(SaatBosluguKampanyasi::satirdan($this->satir(['kampanya_detay' => 'etiketsiz']), $simdi));
    }
}
