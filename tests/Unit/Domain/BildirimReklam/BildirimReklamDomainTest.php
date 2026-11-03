<?php

namespace Tests\Unit\Domain\BildirimReklam;

use App\Domain\BildirimReklam\HedefKosulu;
use App\Domain\BildirimReklam\HedefSegment;
use App\Domain\BildirimReklam\ReklamKuponu;
use App\Domain\BildirimReklam\YayinKurali;
use App\Support\SalonIdListesi;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BildirimReklamDomainTest extends TestCase
{
    private function reklam(array $ek = []): object
    {
        return (object) ($ek + [
            'id' => 1, 'salon_id' => 20, 'gecerli_salonlar' => null, 'durum' => 'aktif',
            'kanal_inapp' => 1, 'tam_ekran' => 0, 'aksiyon_tipi' => 'kupon',
            'kupon_indirim_tipi' => 'yuzde', 'kupon_deger' => 20, 'kupon_baslik' => null, 'kupon_gecerlilik_gun' => null,
            'kupon_hizmet_id' => null, 'kupon_urun_id' => null, 'kupon_kisi_limit' => 1,
            'kupon_toplam_adet' => null, 'kupon_dagitilan' => 0,
            'hedef_kitle' => 'tumu', 'hedef_kosul' => null,
            'yayin_baslangic' => null, 'yayin_bitis' => null,
            'randevu_tarih' => null, 'randevu_tarih_bit' => null,
        ]);
    }

    public function test_hedef_kosulu_db_bicimi_eskiyle_ayni(): void
    {
        $this->assertSame(['hedef_kitle' => 'tumu', 'hedef_kosul' => null], HedefKosulu::tumu()->db());
        $this->assertSame('{"tip":"gelmeyen","gun":60}', HedefKosulu::segment(HedefSegment::GELMEYEN, null)->db()['hedef_kosul']);
        $this->assertSame('{"tip":"cinsiyet","cinsiyet":"1"}', HedefKosulu::segment(HedefSegment::CINSIYET, 1)->db()['hedef_kosul']);
        $this->assertSame('{"tip":"dogum_gunu"}', HedefKosulu::segment(HedefSegment::DOGUM_GUNU, 5)->db()['hedef_kosul']);

        $k = HedefKosulu::dbden('segment', '{"tip":"kisi","user_id":42}');
        $this->assertTrue($k->kisiyeOzelMi());
        $this->assertSame(42, $k->deger);
        $this->assertFalse(HedefKosulu::dbden('segment', 'bozuk')->segmentMi());
        $this->assertFalse(HedefKosulu::dbden('tumu', '{"tip":"kisi","user_id":1}')->segmentMi());
    }

    public function test_kupon_kurali(): void
    {
        $k = ReklamKuponu::satirdan($this->reklam());
        $this->assertTrue($k->verirMi());
        $this->assertSame('%20', $k->kisaMetin());
        $this->assertSame('20% İndirim', $k->baslik());
        $this->assertSame('tumu', $k->gecerliTip());

        $t = ReklamKuponu::satirdan($this->reklam(['kupon_indirim_tipi' => 'tutar', 'kupon_deger' => 150.5, 'kupon_urun_id' => 7, 'kupon_gecerlilik_gun' => 10]));
        $this->assertSame('150,5 ₺ İndirim', $t->baslik());
        $this->assertTrue($t->urunKuponuMu());
        $this->assertSame('2026-11-13', $t->gecerlilikTarihi(CarbonImmutable::parse('2026-11-03 12:00')));

        $this->assertFalse(ReklamKuponu::satirdan($this->reklam(['aksiyon_tipi' => 'yok']))->verirMi());
        $this->assertFalse(ReklamKuponu::satirdan($this->reklam(['aksiyon_tipi' => 'randevu', 'kupon_deger' => 0]))->verirMi());
        $this->assertTrue(ReklamKuponu::satirdan($this->reklam(['aksiyon_tipi' => 'randevu', 'kupon_deger' => 10]))->verirMi());
    }

    public function test_yayin_ve_musteri_gorunurlugu(): void
    {
        $simdi = CarbonImmutable::parse('2026-11-03 12:00');
        $this->assertTrue(YayinKurali::yayindaMi($this->reklam(), $simdi));
        $this->assertFalse(YayinKurali::yayindaMi($this->reklam(['durum' => 'pasif']), $simdi));
        $this->assertFalse(YayinKurali::yayindaMi($this->reklam(['yayin_bitis' => '2026-11-01 00:00:00']), $simdi));
        $this->assertFalse(YayinKurali::yayindaMi($this->reklam(['kupon_toplam_adet' => 5, 'kupon_dagitilan' => 5]), $simdi));

        // Şube grubu
        $grup = $this->reklam(['gecerli_salonlar' => '[20,21]']);
        $this->assertTrue(YayinKurali::musteriyeGorunurMu($grup, 21, 0, 0, $simdi));
        $this->assertFalse(YayinKurali::musteriyeGorunurMu($grup, 120, 0, 0, $simdi));
        $this->assertFalse(YayinKurali::musteriyeGorunurMu($this->reklam(), 21, 0, 0, $simdi));

        // Kişiye özel, kupon kapıldı, kanal yok, boş slot bitmiş
        $kisi = $this->reklam(['hedef_kitle' => 'segment', 'hedef_kosul' => '{"tip":"kisi","user_id":42}']);
        $this->assertTrue(YayinKurali::musteriyeGorunurMu($kisi, 20, 42, 0, $simdi));
        $this->assertFalse(YayinKurali::musteriyeGorunurMu($kisi, 20, 7, 0, $simdi));
        $this->assertFalse(YayinKurali::musteriyeGorunurMu($this->reklam(), 20, 42, 1, $simdi));
        $this->assertFalse(YayinKurali::musteriyeGorunurMu($this->reklam(['kanal_inapp' => 0]), 20, 0, 0, $simdi));
        $this->assertFalse(YayinKurali::musteriyeGorunurMu($this->reklam(['aksiyon_tipi' => 'randevu', 'randevu_tarih' => '2026-11-01']), 20, 0, 0, $simdi));
        $this->assertTrue(YayinKurali::musteriyeGorunurMu($this->reklam(['aksiyon_tipi' => 'randevu', 'randevu_tarih_bit' => '2026-11-03']), 20, 42, 1, $simdi));
    }

    public function test_salon_id_listesi(): void
    {
        $this->assertSame([1, 2], SalonIdListesi::coz('[1,2,2]'));
        $this->assertSame([3, 4], SalonIdListesi::coz('3, 4,0'));
        $this->assertSame([], SalonIdListesi::coz(null));
        $this->assertSame([5], SalonIdListesi::coz(['5', 'x']));
    }
}
