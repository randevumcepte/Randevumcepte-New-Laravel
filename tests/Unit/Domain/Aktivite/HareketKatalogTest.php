<?php

namespace Tests\Unit\Domain\Aktivite;

use App\Domain\Aktivite\HareketKategorisi as K;
use App\Domain\Aktivite\HareketKatalogu;
use App\Domain\Aktivite\HareketKaynagi;
use PHPUnit\Framework\TestCase;

/**
 * Log hareketi sözlüğü (DB'siz). Kategori kuralı eski log-hareketleri.blade ile BİREBİR olmalı;
 * katalogda olmayan (yeni eklenen) kodlar da otomatik etiket + kategori almalı.
 */
class HareketKatalogTest extends TestCase
{
    public function test_kategori_kurali_eski_blade_sirasiyla(): void
    {
        $this->assertSame(K::SILME, K::anahtarKelimeden('randevu_sil'));
        $this->assertSame(K::SILME, K::anahtarKelimeden('hizmet_kaldir'));
        $this->assertSame(K::SILME, K::anahtarKelimeden('randevu_iptal_sil'));      // sil, iptal'den önce
        $this->assertSame(K::IPTAL, K::anahtarKelimeden('e_asistan_gorev_iptal'));
        $this->assertSame(K::EKLEME, K::anahtarKelimeden('musteri_ekle'));
        $this->assertSame(K::EKLEME, K::anahtarKelimeden('sms_ayar_kaydet'));
        $this->assertSame(K::GUNCELLEME, K::anahtarKelimeden('musteri_saglik_guncelle'));
        $this->assertSame(K::GUNCELLEME, K::anahtarKelimeden('oda_durum_degistir'));
        $this->assertSame(K::ODEME, K::anahtarKelimeden('randevu_onayla'));
        $this->assertSame(K::GONDERIM, K::anahtarKelimeden('toplu_sms_gonder'));
        $this->assertSame(K::GIRIS, K::anahtarKelimeden('login'));
        $this->assertSame(K::GIRIS, K::anahtarKelimeden('logout'));
        $this->assertSame(K::DIGER, K::anahtarKelimeden('randevu_tasi'));
    }

    public function test_katalog_kurali_ezer(): void
    {
        // anahtar kelime 'giris' → Giriş derdi; katalog Ödeme / Ekleme yapar.
        $this->assertSame(K::ODEME, HareketKatalogu::kategori('kasa_para_giris'));
        $this->assertSame(K::EKLEME, HareketKatalogu::kategori('stok_alis_girisi'));
        $this->assertSame(K::IPTAL, HareketKatalogu::kategori('randevu_red'));
        $this->assertSame('Giriş yaptı', HareketKatalogu::islem('login')['etiket']);
    }

    public function test_katalogda_olmayan_kod_otomatik_okunur(): void
    {
        $i = HareketKatalogu::islem('kampanya_yayinla');
        $this->assertSame('Kampanya yayınla', $i['etiket']);
        $this->assertSame(K::DIGER, $i['kategori']);

        $this->assertSame('Müşteri sağlık güncelle', HareketKatalogu::okunur('musteri_saglik_guncelle'));
        $this->assertSame('İşletme logo güncelle', HareketKatalogu::okunur('isletme_logo_guncelle'));
        $this->assertSame('E-asistan ayar kaydet', HareketKatalogu::okunur('e_asistan_ayar_kaydet'));
        $this->assertSame('SMS kara liste ekle', HareketKatalogu::okunur('sms_karaliste_ekle'));
        $this->assertSame('İşlem', HareketKatalogu::okunur('__'));
    }

    public function test_hedef_etiketi(): void
    {
        $this->assertSame('Satış', HareketKatalogu::hedefEtiketi('adisyon'));
        $this->assertSame('Müşteri', HareketKatalogu::hedefEtiketi('musteri'));
        $this->assertNull(HareketKatalogu::hedefEtiketi(null));
        $this->assertNull(HareketKatalogu::hedefEtiketi(''));
    }

    public function test_kaynak_cozumle(): void
    {
        $this->assertSame(HareketKaynagi::MOBIL, HareketKaynagi::cozumle(['kaynak' => 'mobil'], 'sistem'));
        $this->assertSame(HareketKaynagi::PANEL, HareketKaynagi::cozumle(['kaynak' => 'panel_oto'], 'yetkili'));
        $this->assertSame(HareketKaynagi::V2, HareketKaynagi::cozumle(['kaynak' => 'v2'], 'yetkili'));
        $this->assertSame(HareketKaynagi::PANEL, HareketKaynagi::cozumle(null, 'yetkili'));   // eski elle Audit::log
        $this->assertSame(HareketKaynagi::SISTEM, HareketKaynagi::cozumle([], 'sistem'));
        $this->assertSame(['panel', 'panel_oto'], HareketKaynagi::PANEL->metaDegerleri());
        $this->assertFalse(HareketKaynagi::PANEL->kaynaksizSistemMi());
        $this->assertNull(HareketKaynagi::MOBIL->kaynaksizSistemMi());
    }
}
