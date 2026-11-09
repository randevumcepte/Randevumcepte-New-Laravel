<?php

namespace Tests\Unit\Personel;

use App\Domain\Personel\HesapSahibiKorumasi as K;
use PHPUnit\Framework\TestCase;

/** Hesap sahibi korumasi: pasif/arsiv, telefon, sifre gonder, rol degisikligi ve rol 1 atamasi tek saf kuralda. */
class HesapSahibiKorumasiTest extends TestCase
{
    public function test_pasif_ve_arsiv_hesap_sahibi_ve_kendi_satiri_reddedilir(): void
    {
        $this->assertSame('Hesap sahibi pasife alınamaz.', K::engel(K::PASIF, true, 5, 9));
        $this->assertSame('Hesap sahibi pasife alınamaz.', K::engel(K::PASIF, true, 9, 9), 'sahip kendini de pasife alamaz');
        $this->assertSame('Kendi personel kaydınızı pasife alamazsınız.', K::engel(K::PASIF, false, 9, 9));
        $this->assertSame('Hesap sahibi arşivlenemez.', K::engel(K::ARSIV, true, 5, 9, true), 'sistem yonetimi de arsivleyemez');
        $this->assertSame('Kendi personel kaydınızı arşivleyemezsiniz.', K::engel(K::ARSIV, false, 9, 9));
    }

    public function test_diger_personel_pasife_alinir(): void
    {
        $this->assertNull(K::engel(K::PASIF, false, 8, 9));
        $this->assertNull(K::engel(K::PASIF, false, null, 9), 'giris hesabi olmayan personel');
        $this->assertNull(K::engel(K::ARSIV, false, 8, null), 'yapan bilinmiyor (sistem)');
    }

    public function test_telefon_ve_sifre_yalniz_sahibin_kendisi(): void
    {
        $this->assertNotNull(K::engel(K::TELEFON, true, 5, 9));
        $this->assertNotNull(K::engel(K::TELEFON, true, 5, null), 'yapan bilinmiyorsa da reddedilir');
        $this->assertNotNull(K::engel(K::TELEFON, true, null, 9), 'sahip satirinin hesabi yoksa yalniz sahip degil');
        $this->assertNull(K::engel(K::TELEFON, true, 9, 9));
        $this->assertNull(K::engel(K::TELEFON, false, 5, 9));
        $this->assertNotNull(K::engel(K::SIFRE, true, 5, 9));
        $this->assertNotNull(K::engel(K::SIFRE, true, 5, 9, true), 'sifre/telefon icin sistem istisnasi yok');
        $this->assertNull(K::engel(K::SIFRE, true, 5, 5));
        $this->assertNull(K::engel(K::SIFRE, false, 5, 9));
    }

    public function test_sahibin_rolu_yalniz_kendisi_veya_sistem(): void
    {
        $this->assertNotNull(K::engel(K::ROL, true, 5, 9));
        $this->assertNull(K::engel(K::ROL, true, 5, 5));
        $this->assertNull(K::engel(K::ROL, true, 5, 9, true));
        $this->assertNull(K::engel(K::ROL, false, 5, 9));
    }

    public function test_sahibin_yetkileri_yalniz_kendisi_veya_sistem(): void
    {
        $this->assertSame('Hesap sahibinin yetkilerini yalnız kendisi değiştirebilir.', K::engel(K::YETKI, true, 5, 9));
        $this->assertNotNull(K::engel(K::YETKI, true, 5, null), 'yapan bilinmiyorsa da reddedilir');
        $this->assertNull(K::engel(K::YETKI, true, 5, 5));
        $this->assertNull(K::engel(K::YETKI, true, 5, 9, true), 'sistem yonetimi');
        $this->assertNull(K::engel(K::YETKI, false, 5, 9));
    }

    public function test_rol_atama_gecerli_liste_ve_rol_1(): void
    {
        $roller = [1, 2, 3, 4, 5, 7, 8];
        $this->assertNull(K::rolAtamaEngeli(null, 5, $roller, false));
        $this->assertNull(K::rolAtamaEngeli('', 5, $roller, false));
        $this->assertSame('Geçersiz hesap türü.', K::rolAtamaEngeli(6, 5, $roller, true));
        $this->assertSame('Geçersiz hesap türü.', K::rolAtamaEngeli('99', 5, $roller, true));
        $this->assertSame('Geçersiz hesap türü.', K::rolAtamaEngeli('2a', 5, $roller, true));
        $this->assertSame('Geçersiz hesap türü.', K::rolAtamaEngeli(2.5, 5, $roller, true));
        $this->assertNull(K::rolAtamaEngeli('4', 5, $roller, false));
        $this->assertSame('Hesap sahibi rolünü yalnız hesap sahibi atayabilir.', K::rolAtamaEngeli(1, 5, $roller, false));
        $this->assertNull(K::rolAtamaEngeli(1, 5, $roller, true), 'sahip atar');
        $this->assertNull(K::rolAtamaEngeli(1, 5, $roller, false, true), 'sistem yonetimi atar');
        $this->assertNull(K::rolAtamaEngeli(1, 1, $roller, false), 'zaten sahip: degisiklik yok');
    }

    public function test_bilinmeyen_islem_hata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        K::engel('yok', true, 1, 1);
    }
}
