<?php

namespace Tests\Unit\Domain\Seans;

use App\Domain\Seans\MobilSeansKurali;
use PHPUnit\Framework\TestCase;

/** Eski ApiController::seans_getir, paketVarmiKontroluApi ve seansEkle ile ayni sayilar. */
class MobilSeansKuraliTest extends TestCase
{
    public function test_bekleyen_kolondan_duser_eksi_olabilir(): void
    {
        $s = [['geldi' => 1, 'iptal' => 0], ['geldi' => 1, 'iptal' => 0], ['geldi' => 0, 'iptal' => 0], ['geldi' => null, 'iptal' => 0], ['geldi' => 2, 'iptal' => 0]];

        $this->assertSame(['bekleyen' => 7, 'gelinen' => 2, 'gelinmeyen' => 1], MobilSeansKurali::sayac($s, 10));
        $this->assertSame(['bekleyen' => -3, 'gelinen' => 2, 'gelinmeyen' => 1], MobilSeansKurali::sayac($s, null));
    }

    public function test_iptal_gelinmeyene_sayilir_geldi_ile_birlikte_iki_sayaca_girer(): void
    {
        $r = MobilSeansKurali::sayac([['geldi' => null, 'iptal' => 1], ['geldi' => 1, 'iptal' => 1]], 5);

        $this->assertSame(['bekleyen' => 2, 'gelinen' => 1, 'gelinmeyen' => 2], $r);
    }

    public function test_sinirsizda_bekleyen_acilmis_seanstan_telafi_de_duser(): void
    {
        $s = [['geldi' => 1], ['geldi' => 0], ['geldi' => 2], ['geldi' => null], ['geldi' => null]];

        $this->assertSame(['bekleyen' => 2, 'gelinen' => 1, 'gelinmeyen' => 1], MobilSeansKurali::sayac($s, 1000, true));
    }

    public function test_metin_gelen_geldi_de_sayilir(): void
    {
        $this->assertSame(['bekleyen' => 3, 'gelinen' => 1, 'gelinmeyen' => 1], MobilSeansKurali::sayac([['geldi' => '1'], ['geldi' => '0']], 5));
    }

    public function test_hizmet_kalani_eksiye_dusmez_seans_bossa_bekleyen(): void
    {
        $this->assertSame(3, MobilSeansKurali::hizmetKalani(5, 99, 2));
        $this->assertSame(0, MobilSeansKurali::hizmetKalani(1, 99, 2));
        $this->assertSame(2, MobilSeansKurali::hizmetKalani(null, 4, 2));
        $this->assertSame(0, MobilSeansKurali::hizmetKalani(null, null, 0));
    }

    public function test_paket_kalani_hizmet_basina_asagi_yuvarlanir(): void
    {
        $this->assertSame(8, MobilSeansKurali::paketKalani(8, null, 3, 0));
        $this->assertSame(7, MobilSeansKurali::paketKalani(8, null, 3, 1));
        $this->assertSame(7, MobilSeansKurali::paketKalani(8, null, 3, 3));
        $this->assertSame(0, MobilSeansKurali::paketKalani(1, null, 3, 5));
        $this->assertSame(0, MobilSeansKurali::paketKalani(8, null, 0, 0));
        $this->assertSame(2, MobilSeansKurali::paketKalani(null, 2, 1, 0));
    }

    public function test_seans_tarihi_gecersizse_bugun(): void
    {
        $this->assertSame('2026-11-07', MobilSeansKurali::seansTarihi(null, '2026-11-07'));
        $this->assertSame('2026-11-07', MobilSeansKurali::seansTarihi('', '2026-11-07'));
        $this->assertSame('2026-11-07', MobilSeansKurali::seansTarihi('07.11.2026', '2026-11-07'));
        $this->assertSame('2026-11-07', MobilSeansKurali::seansTarihi('2026-02-31', '2026-11-07'));
        $this->assertSame('2026-01-15', MobilSeansKurali::seansTarihi('2026-01-15', '2026-11-07'));
    }
}
