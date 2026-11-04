<?php

namespace Tests\Unit\Domain\Bildirim;

use App\Domain\Bildirim\BildirimHedefi;
use App\Domain\Bildirim\CihazSahibi;
use PHPUnit\Framework\TestCase;

class BildirimDomainTest extends TestCase
{
    private function hedef(?string $url, array $gorsel = [], ?int $randevu = null, ?int $user = null): BildirimHedefi
    {
        return BildirimHedefi::coz($url, $gorsel, $randevu, $user);
    }

    public function test_hash_onekleri_eski_kuralla_ayni(): void
    {
        $this->assertSame(BildirimHedefi::AJANDA, $this->hedef('#not-5', [], 3, 4)->tur);
        $this->assertSame(BildirimHedefi::RANDEVULAR, $this->hedef('#form-5', [], 3, 4)->tur);
    }

    public function test_gercek_url_oncelikli(): void
    {
        $h = $this->hedef('/isletmeyonetim/randevular', [], 3, 4);
        $this->assertSame(BildirimHedefi::URL, $h->tur);
        $this->assertSame('/isletmeyonetim/randevular', $h->url);
    }

    public function test_diyez_url_randevu_musterisine_sonra_bildirim_musterisine_duser(): void
    {
        $this->assertSame(3, $this->hedef('#', [], 3, 4)->musteriId);
        $this->assertSame(4, $this->hedef('#', [], null, 4)->musteriId);
        $this->assertSame(4, $this->hedef(null, [], null, 4)->musteriId);
    }

    public function test_hicbir_bilgi_yoksa_randevular(): void
    {
        $this->assertSame(BildirimHedefi::RANDEVULAR, $this->hedef('', [], null, null)->tur);
    }

    public function test_url_kolonundaki_gorsel_hedef_sayilmaz(): void
    {
        $gorsel = 'https://cdn.test/k.png';
        $this->assertSame(BildirimHedefi::MUSTERI, $this->hedef($gorsel, [$gorsel, null], null, 4)->tur);
    }

    public function test_guvensiz_sema_ve_protokolsuz_adres_hedef_olmaz(): void
    {
        $this->assertSame(BildirimHedefi::RANDEVULAR, $this->hedef('javascript:alert(1)')->tur);
        $this->assertSame(BildirimHedefi::RANDEVULAR, $this->hedef('//kotu.test/x')->tur);
    }

    public function test_cihaz_sahibi_diger_rol_kolonunu_bosaltir(): void
    {
        $this->assertSame(['user_id' => 9, 'isletme_yetkili_id' => null], CihazSahibi::musteri(9, null)->sahipKolonlari());
        $sahip = CihazSahibi::personel(7, 1, false);
        $this->assertSame(['user_id' => null, 'isletme_yetkili_id' => 7], $sahip->sahipKolonlari());
        $this->assertSame('personel', $sahip->kullaniciTipi);
        $this->assertSame('yetkili', CihazSahibi::personel(7, 1, true)->kullaniciTipi);
    }
}
