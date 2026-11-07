<?php

namespace Tests\Unit\Domain\Randevu;

use App\Domain\Randevu\HizmetZinciri;
use App\Domain\Randevu\SaatKapama;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Mobil isteginin eski kayit kurallari; web varsayilani degismez. */
class HizmetZinciriMobilTest extends TestCase
{
    public function test_varsayilan_bos_sureyi_reddeder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HizmetZinciri::satirlar('10:00', [['sure_dk' => '']]);
    }

    public function test_mobil_bos_sure_otuz_dakika(): void
    {
        $s = HizmetZinciri::satirlar('10:00', [['sure_dk' => 'null'], ['sure_dk' => 15]], true);
        $this->assertSame(['saat' => '10:00', 'saat_bitis' => '10:30', 'sure_dk' => 30], $s[0]);
        $this->assertSame('10:30', $s[1]['saat']);
        $this->assertSame('10:45', HizmetZinciri::toplamBitis('10:00', [['sure_dk' => ''], ['sure_dk' => 15]], true));
    }

    public function test_mobil_gece_yarisini_gecen_saat_basa_sarar(): void
    {
        $this->assertSame('00:30', HizmetZinciri::satirlar('23:30', [['sure_dk' => 60]], true)[0]['saat_bitis']);
        $this->assertSame('00:30', HizmetZinciri::toplamBitis('23:30', [['sure_dk' => 60]], true));
    }

    public function test_varsayilan_gece_yarisini_gecemez(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HizmetZinciri::satirlar('23:30', [['sure_dk' => 60]]);
    }

    public function test_tekrar_tavani_cagirandan_gelir(): void
    {
        $this->assertCount(60, SaatKapama::tarihler('2026-11-07', 60, '+1 day', 366));
        $this->expectException(InvalidArgumentException::class);
        SaatKapama::tarihler('2026-11-07', 60, '+1 day');
    }
}
