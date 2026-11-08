<?php

namespace Tests\Unit\Domain\Karsilastirma;

use App\Domain\Karsilastirma\MobilTutarKontrolleri as Liste;
use PHPUnit\Framework\TestCase;

class MobilTutarKontrolleriTest extends TestCase
{
    public function test_liste_yazma_ucu_icermez(): void
    {
        foreach (Liste::hepsi() as $k) {
            $this->assertContains($k['eski'][0], ['GET', 'POST'], $k['ad']);
            $this->assertFalse(Liste::yazmaYoluMu($k['eski'][1]), $k['ad'].' eski yolu');
            $this->assertFalse(Liste::yazmaYoluMu($k['yeni'][0]), $k['ad'].' yeni yolu');
        }
    }

    public function test_yazma_yollari_taninir(): void
    {
        $yazmalar = ['/tahsilatekle', '/taksitekleguncelle', '/senetekleguncelle', '/harici-tahsilat-ekle', '/randevutahsilet', '/stok/hizli-satis/20',
            '/cagri-merkezi/hizli-satis', '/kampanyaAdmin/kod-kullan/20', '/paket_sil', '/primOde', '/primOdemeSil', '/senetode', '/seansEkle', '/seansGuncelle',
            '/adisyonhizmetekle', '/whatsapp/paket-talep/20', '/sms-yonetim/toplu-gonder/20', '/faturasizgizletoggle', '/adisyonfaturaisaretle', '/masrafekleduzenle/20'];
        foreach ($yazmalar as $yol) {
            $this->assertTrue(Liste::yazmaYoluMu($yol), $yol);
        }
    }

    public function test_adlar_tekildir_ve_her_kontrolde_alan_vardir(): void
    {
        $adlar = array_column(Liste::hepsi(), 'ad');

        $this->assertSame(count($adlar), count(array_unique($adlar)));
        foreach (Liste::hepsi() as $k) {
            $this->assertNotEmpty($k['alanlar'], $k['ad']);
        }
    }

    public function test_alan_sonuclarindaki_okuma_kontrolleri_listededir(): void
    {
        $adlar = array_column(Liste::hepsi(), 'ad');
        foreach (['Kasa ozeti', 'Tahsilat listesi', 'Masraf listesi', 'Gun sonu', 'Devreden aylar', 'Hizmet raporu', 'Urun raporu', 'Paket raporu', 'Personel raporu',
            'Personel prim ay/yil', 'Prim odeme listesi', 'Alacaklar', 'Alacaklar v2', 'Harici kalemler', 'Cagri kalemleri', 'Kampanya kodlari', 'Kampanya kodu adisyon durumu',
            'Gunluk paket satislari', 'Gunluk urun satislari', 'Musteri turu', 'Faturasiz gizle durumu', 'Paket tablosu', 'Seans takibi listesi', 'Randevu formu kalan seans',
            'Ana ekran ozeti', 'WhatsApp paket durumu'] as $ad) {
            $this->assertContains($ad, $adlar);
        }
        $this->assertArrayHasKey('Paket yazma satirlari', Liste::KAPSAM_DISI);
    }

    public function test_dagitim_yollari_bilincli_fark_olarak_isaretli(): void
    {
        foreach (['/tahsilatekle kalem paylari', '/tahsilatekle taksit ve senet vadesi', '/harici-tahsilat-ekle kalem paylari'] as $yol) {
            $this->assertArrayHasKey($yol, Liste::BILINCLI_FARK);
            $this->assertStringStartsWith('bilincli fark: web kurali', Liste::BILINCLI_FARK[$yol]);
        }
    }
}
