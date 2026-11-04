<?php

namespace Tests\Unit\Domain\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\CagriMerkeziEylemi as E;
use App\Domain\CagriMerkezi\CagriMerkeziSekmeleri;
use App\Support\Yetki\YetkiSabitleri;
use PHPUnit\Framework\TestCase;

/** Çağrı merkezi eylem kayıt defteri + rol/anahtar kapısı (DB/HTTP'siz). */
class CagriMerkeziEylemiTest extends TestCase
{
    private function erisim(bool $yonetici, array $yetkiler = []): CagriMerkeziErisim
    {
        return new CagriMerkeziErisim(20, 7, $yonetici, 55, ['101'], false, $yetkiler);
    }

    public function test_personele_kapali_bolumler_ayar_liste_performans(): void
    {
        $kapali = array_values(array_filter(E::cases(), fn (E $e) => $e->yalnizYonetici()));

        $this->assertSame([E::Performans, E::ListeYonet, E::AyarYonet], $kapali);
        foreach ($kapali as $e) {
            $this->assertFalse($this->erisim(false)->yapabilir($e));
            $this->assertTrue($this->erisim(true)->yapabilir($e));
            $this->assertSame('Bu bölüm personel hesaplarına kapalıdır', $e->redMesaji());
        }
    }

    public function test_personele_acik_calisma_bolumleri(): void
    {
        foreach ([E::Calisma, E::AramaRandevu, E::CagriKayitlari, E::Arama, E::Whatsapp] as $e) {
            $this->assertTrue($this->erisim(false)->yapabilir($e), $e->value);
        }
    }

    public function test_anahtarli_eylem_rol_ne_olursa_olsun_bayrak_ister(): void
    {
        foreach ([E::Satis, E::FiyatDegistir, E::Anket, E::OnGorusme] as $e) {
            $this->assertFalse($this->erisim(true)->yapabilir($e), $e->value);
            $this->assertFalse($this->erisim(false, [$e->value => false])->yapabilir($e), $e->value);
            $this->assertTrue($this->erisim(false, [$e->value => true])->yapabilir($e), $e->value);
        }
    }

    public function test_red_mesajlari(): void
    {
        $this->assertSame('Satış için yetkiniz yok.', E::Satis->redMesaji());
        $this->assertSame('Anket gönderme yetkiniz yok.', E::Anket->redMesaji());
        $this->assertSame('Bu işlem için yetkiniz yok', E::FiyatDegistir->redMesaji());
    }

    /** Eyleme yazılan her anahtar yetki kataloğunda olmalı (yazım hatası kapıyı sessizce kilitler). */
    public function test_eylem_anahtarlari_katalogda_tanimli(): void
    {
        foreach (E::cases() as $e) {
            $this->assertSame([], array_diff($e->anahtarlar(), YetkiSabitleri::tumAnahtarlar()), $e->value);
        }
    }

    public function test_sekmeler_eylem_kuralindan_gelir_ve_istemciye_eylem_sizmaz(): void
    {
        $personel = CagriMerkeziSekmeleri::gorunur($this->erisim(false));

        $this->assertSame(['calisma', 'ajanda', 'takvim', 'kayitlar'], array_column($personel, 'kod'));
        $this->assertArrayNotHasKey('eylem', $personel[0]);
        $this->assertFalse(CagriMerkeziSekmeleri::acikMi('ayarlar', $this->erisim(false)));
        $this->assertFalse(CagriMerkeziSekmeleri::acikMi('listeler', $this->erisim(false)));
        $this->assertTrue(CagriMerkeziSekmeleri::acikMi('ayarlar', $this->erisim(true)));
    }

    public function test_dizi_telefon_bayragi_maskeyle_ayni(): void
    {
        $this->assertFalse((new CagriMerkeziErisim(20, 3, true, null, [], false))->dizi()['telefon_gor']);
        $this->assertTrue((new CagriMerkeziErisim(20, 3, true, null, [], true))->dizi()['telefon_gor']);
    }
}
