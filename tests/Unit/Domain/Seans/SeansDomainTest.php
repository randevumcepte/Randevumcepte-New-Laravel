<?php

namespace Tests\Unit\Domain\Seans;

use App\Domain\Seans\LazerTespiti;
use App\Domain\Seans\SeansDurumu;
use App\Domain\Seans\SeansKalemTuru;
use App\Domain\Seans\SeansSayaci;
use PHPUnit\Framework\TestCase;

/**
 * Seans takibi saf kurallari — sayaclar eski web ekraniyla ayni, durum sozlugu
 * DB degerleriyle (null/0/1/2) birebir.
 */
class SeansDomainTest extends TestCase
{
    public function test_durum_db_degerinden_cozulur_string_dahil(): void
    {
        $this->assertSame(SeansDurumu::BEKLEMEDE, SeansDurumu::geldidenCoz(null));
        $this->assertSame(SeansDurumu::BEKLEMEDE, SeansDurumu::geldidenCoz(''));
        $this->assertSame(SeansDurumu::GELMEDI, SeansDurumu::geldidenCoz(0));
        $this->assertSame(SeansDurumu::GELMEDI, SeansDurumu::geldidenCoz('0'));
        $this->assertSame(SeansDurumu::GELDI, SeansDurumu::geldidenCoz('1'));
        $this->assertSame(SeansDurumu::TELAFI, SeansDurumu::geldidenCoz(2));
        $this->assertSame(SeansDurumu::BEKLEMEDE, SeansDurumu::geldidenCoz(7));
    }

    public function test_durum_geldi_degeri_geri_donusur(): void
    {
        foreach (SeansDurumu::cases() as $d) {
            $this->assertSame($d, SeansDurumu::geldidenCoz($d->geldi()));
        }
        $this->assertCount(4, SeansDurumu::sozluk());
    }

    public function test_sayac_eski_web_formulu_telafi_kapasite_tuketir_beklemede_tuketmez(): void
    {
        $s = SeansSayaci::hesapla(10, [1, 1, 0, 2, null, '1']);

        $this->assertSame(['toplam' => 10, 'bekleyen' => 5, 'kullanilan' => 3, 'kullanilmayan' => 1, 'telafi' => 1], $s);
    }

    public function test_sayac_eksiye_dusmez(): void
    {
        $s = SeansSayaci::hesapla(0, [1, 0]);

        $this->assertSame(0, $s['bekleyen']);
        $this->assertSame(0, $s['toplam']);
    }

    public function test_paket_toplami_hizmet_sayisiyla_carpilir_bos_hak(): void
    {
        $this->assertSame(18, SeansSayaci::kalemToplami(6, 3));
        $this->assertSame(0, SeansSayaci::kalemToplami(-1, 3));
        $this->assertSame(2, SeansSayaci::bosHak(6, 4));
        $this->assertSame(0, SeansSayaci::bosHak(6, 9));
    }

    public function test_kalem_turu_seans_satirindan(): void
    {
        $paket = (object) ['adisyon_paket_id' => 5, 'adisyon_hizmet_id' => null];
        $hizmet = (object) ['adisyon_paket_id' => null, 'adisyon_hizmet_id' => 9];

        $this->assertSame(SeansKalemTuru::PAKET, SeansKalemTuru::seanstan($paket));
        $this->assertSame(5, SeansKalemTuru::PAKET->seansKalemId($paket));
        $this->assertSame(SeansKalemTuru::HIZMET, SeansKalemTuru::seanstan($hizmet));
        $this->assertSame('adisyon_hizmetler', SeansKalemTuru::HIZMET->tablo());
        $this->assertNull(SeansKalemTuru::seanstan((object) ['adisyon_paket_id' => null, 'adisyon_hizmet_id' => null]));
    }

    public function test_lazer_tespiti_ad_listesinde(): void
    {
        $this->assertTrue(LazerTespiti::adlardanBiri('Tüm Vücut', 'Buz LAZER Epilasyon'));
        $this->assertTrue(LazerTespiti::adlardanBiri('Diode Laser'));
        $this->assertFalse(LazerTespiti::adlardanBiri('Cilt Bakımı', null, ''));
    }
}
