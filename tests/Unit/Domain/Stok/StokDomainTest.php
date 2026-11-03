<?php

namespace Tests\Unit\Domain\Stok;

use App\Domain\Stok\HareketTipi;
use App\Domain\Stok\Miktar;
use App\Domain\Stok\StokSeviyesi;
use PHPUnit\Framework\TestCase;

class StokDomainTest extends TestCase
{
    public function test_seviye_tukendi_sifir_ve_eksi(): void
    {
        $this->assertSame(StokSeviyesi::TUKENDI, StokSeviyesi::hesapla(0, 5, 2));
        $this->assertSame(StokSeviyesi::TUKENDI, StokSeviyesi::hesapla(-3, null, null));
    }

    public function test_seviye_kritik_dusukten_once_gelir(): void
    {
        $this->assertSame(StokSeviyesi::KRITIK, StokSeviyesi::hesapla(2, 5, 2));
        $this->assertSame(StokSeviyesi::DUSUK, StokSeviyesi::hesapla(4, 5, 2));
        $this->assertSame(StokSeviyesi::NORMAL, StokSeviyesi::hesapla(6, 5, 2));
    }

    public function test_sinir_tanimsiz_veya_sifirsa_normal(): void
    {
        $this->assertSame(StokSeviyesi::NORMAL, StokSeviyesi::hesapla(1, null, null));
        $this->assertSame(StokSeviyesi::NORMAL, StokSeviyesi::hesapla(1, 0, 0));
    }

    public function test_yalniz_kritik_tanimliyken_uyari_sayilir(): void
    {
        // Eski ozet bunu saymiyordu (yalniz dusuk siniri bakiyordu); v2'de rozetle tutarli.
        $this->assertTrue(StokSeviyesi::uyariMi(StokSeviyesi::hesapla(1, null, 3)));
        $this->assertFalse(StokSeviyesi::uyariMi(StokSeviyesi::TUKENDI));
    }

    public function test_seviye_renkleri(): void
    {
        $this->assertSame('kirmizi', StokSeviyesi::renk(StokSeviyesi::KRITIK));
        $this->assertSame('sari', StokSeviyesi::renk(StokSeviyesi::DUSUK));
        $this->assertSame('yesil', StokSeviyesi::renk(StokSeviyesi::NORMAL));
    }

    public function test_cikis_tipleri_daima_negatif_giris_tipleri_daima_pozitif(): void
    {
        foreach ([HareketTipi::Satis, HareketTipi::Sarf, HareketTipi::Fire, HareketTipi::TransferCikis] as $t) {
            $this->assertSame(-5.0, $t->isaretle(5), $t->value);
            $this->assertSame(-5.0, $t->isaretle(-5), $t->value);
        }
        foreach ([HareketTipi::Alis, HareketTipi::Iade, HareketTipi::TransferGiris] as $t) {
            $this->assertSame(5.0, $t->isaretle(-5), $t->value);
        }
    }

    public function test_duzeltme_tipleri_isareti_korur(): void
    {
        foreach ([HareketTipi::Manuel, HareketTipi::Sayim, HareketTipi::Acilis] as $t) {
            $this->assertSame(-2.5, $t->isaretle(-2.5), $t->value);
            $this->assertSame(2.5, $t->isaretle(2.5), $t->value);
        }
    }

    public function test_manuel_secilebilir_tipler_ve_bilinmeyen_tip_sunumu(): void
    {
        $this->assertSame(['fire', 'manuel', 'iade', 'sarf'], HareketTipi::manuelDegerler());
        $this->assertSame(['etiket' => 'eski_tip', 'renk' => '#455A64'], HareketTipi::sunum('eski_tip'));
        $this->assertSame('Transfer Giriş', HareketTipi::sunum('transfer_giris')['etiket']);
    }

    public function test_miktar_fark_ve_esik(): void
    {
        $this->assertSame(-1.5, Miktar::fark(3.5, 5));
        $this->assertSame(0.1, Miktar::fark(0.3, 0.2));   // float hatasi yuvarlanir
        $this->assertTrue(Miktar::sifirMi(Miktar::fark(0.1 + 0.2, 0.3)));
        $this->assertSame(1.235, Miktar::yuvarla(1.23456));
    }
}
