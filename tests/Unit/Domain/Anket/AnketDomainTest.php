<?php

namespace Tests\Unit\Domain\Anket;

use App\Domain\Anket\AnketOdulu;
use App\Domain\Anket\AnketPuanlama;
use App\Domain\Anket\AnketSorulari;
use App\Domain\Anket\GonderimDurumu;
use App\Domain\Anket\GonderimFiltresi;
use App\Domain\Anket\GoogleYorumBaglantisi;
use App\Domain\Anket\ReputasyonEsikleri;
use App\Domain\Anket\SoruTipi;
use PHPUnit\Framework\TestCase;

/** Memnuniyet anketi saf kuralları (DB'siz). */
class AnketDomainTest extends TestCase
{
    public function test_flutter_baslik_anahtari_soru_olarak_da_yazilir(): void
    {
        $s = AnketSorulari::normalize([['tip' => 'nps', 'baslik' => 'Tavsiye eder misiniz?', 'zorunlu' => true]]);
        $this->assertSame('Tavsiye eder misiniz?', $s[0]['soru']);
        $this->assertSame('Tavsiye eder misiniz?', $s[0]['baslik']);
        $this->assertTrue($s[0]['zorunlu']);
    }

    public function test_json_metni_kabul_edilir_ve_eski_csat_tipi_kanoniklesir(): void
    {
        $s = AnketSorulari::normalize('[{"tip":"csat","soru":"Memnun musunuz?"}]');
        $this->assertSame('csat_yildiz', $s[0]['tip']);
        $this->assertFalse($s[0]['zorunlu']);
    }

    public function test_yapi_elemani_zorunlu_olamaz_ve_tek_basina_yetmez(): void
    {
        $s = AnketSorulari::normalize([['tip' => 'bolum_basligi', 'soru' => 'B', 'zorunlu' => true], ['tip' => 'metin', 'soru' => 'S']]);
        $this->assertFalse($s[0]['zorunlu']);

        $this->expectException(\InvalidArgumentException::class);
        AnketSorulari::normalize([['tip' => 'bolum_basligi', 'soru' => 'B']]);
    }

    public function test_bos_metin_gecersiz_tip_ve_seceneksiz_soru_reddedilir(): void
    {
        foreach ([
            [['tip' => 'nps', 'soru' => '  ']],
            [['tip' => 'yok_boyle', 'soru' => 'S']],
            [['tip' => 'tek_secim', 'soru' => 'S', 'secenekler' => [' ', '']]],
            'bozuk json',
        ] as $girdi) {
            try {
                AnketSorulari::normalize($girdi);
                $this->fail('Reddedilmeliydi: '.json_encode($girdi));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_secenekler_temizlenir(): void
    {
        $s = AnketSorulari::normalize([['tip' => 'cok_secim', 'soru' => 'S', 'secenekler' => [' A ', '', 'B']]]);
        $this->assertSame(['A', 'B'], $s[0]['secenekler']);
    }

    public function test_coz_indeksi_korur_bozuk_veride_hata_atmaz(): void
    {
        $s = AnketSorulari::coz('[{"tip":"bilinmeyen","soru":"X"},{"tip":"metin","baslik":"Y"}]');
        $this->assertCount(2, $s);
        $this->assertSame('Y', $s[1]['soru']);
        $this->assertSame([], AnketSorulari::coz('bozuk'));
        $this->assertSame([], AnketSorulari::coz(null));
    }

    public function test_hazir_sablon_gecerlidir(): void
    {
        $this->assertCount(4, AnketSorulari::normalize(AnketSorulari::hazirSablon()));
    }

    public function test_katalog_tum_tipleri_icerir(): void
    {
        $k = SoruTipi::katalog();
        $this->assertCount(count(SoruTipi::cases()), $k['tipler']);
        $this->assertCount(3, $k['gruplar']);
    }

    public function test_odul_yalniz_secili_tipin_alanlarini_tutar(): void
    {
        $k = AnketOdulu::girdiden([
            'odul_tipi' => 'puan', 'odul_puan' => 50, 'odul_kupon_deger' => 99, 'odul_baslik' => ' Hediye ',
        ], AnketOdulu::ONEK_SABLON)->kolonlar(AnketOdulu::ONEK_SABLON);
        $this->assertSame('puan', $k['odul_tipi']);
        $this->assertSame(50.0, $k['odul_puan']);
        $this->assertNull($k['odul_kupon_deger']);
        $this->assertSame('Hediye', $k['odul_baslik']);
    }

    public function test_google_odulunde_hizmet_kolonu_yoktur_gun_sifir_suresizdir(): void
    {
        $k = AnketOdulu::girdiden([
            'google_odul_tipi' => 'kupon', 'google_odul_kupon_deger' => 10, 'google_odul_kupon_gecerlilik_gun' => 0,
        ], AnketOdulu::ONEK_GOOGLE)->kolonlar(AnketOdulu::ONEK_GOOGLE);
        $this->assertArrayNotHasKey('google_odul_kupon_hizmet_id', $k);
        $this->assertNull($k['google_odul_kupon_gecerlilik_gun']);
        $this->assertSame('yuzde', $k['google_odul_kupon_indirim_tipi']);
    }

    public function test_bilinmeyen_odul_tipi_yok_sayilir(): void
    {
        $o = AnketOdulu::girdiden(['odul_tipi' => 'x', 'odul_puan' => 5], AnketOdulu::ONEK_SABLON);
        $this->assertSame('yok', $o->tip);
        $this->assertNull($o->kisaEtiket());
    }

    public function test_odul_etiketleri(): void
    {
        $e = fn (array $r) => AnketOdulu::satirdan((object) $r, 'odul_')->kisaEtiket();
        $this->assertSame('%10 Kupon', $e(['odul_tipi' => 'kupon', 'odul_kupon_indirim_tipi' => 'yuzde', 'odul_kupon_deger' => '10.00']));
        $this->assertSame('1.000 ₺ Kupon', $e(['odul_tipi' => 'kupon', 'odul_kupon_indirim_tipi' => 'tutar', 'odul_kupon_deger' => '1000.00']));
        $this->assertSame('10,5 Puan', $e(['odul_tipi' => 'puan', 'odul_puan' => '10.50']));
    }

    public function test_nps_sinifi_sinirlari(): void
    {
        $this->assertNull(AnketPuanlama::npsSinifi(null));
        $this->assertSame('detractor', AnketPuanlama::npsSinifi(6));
        $this->assertSame('pasif', AnketPuanlama::npsSinifi(7));
        $this->assertSame('pasif', AnketPuanlama::npsSinifi(8));
        $this->assertSame('promoter', AnketPuanlama::npsSinifi(9));
    }

    public function test_web_yayin_kurali_mini_site_sorgusuyla_aynidir(): void
    {
        $this->assertTrue(AnketPuanlama::webeUygunMu(true, 'İyi', 9, null));
        $this->assertTrue(AnketPuanlama::webeUygunMu(true, 'İyi', null, 4.5));
        $this->assertFalse(AnketPuanlama::webeUygunMu(true, '  ', 10, 5.0));
        $this->assertFalse(AnketPuanlama::webeUygunMu(false, 'İyi', 10, 5.0));
        $this->assertFalse(AnketPuanlama::webeUygunMu(true, 'İyi', 8, 4.4));
    }

    public function test_gonderim_durumu(): void
    {
        $simdi = '2026-11-04 12:00:00';
        $this->assertSame(GonderimDurumu::CEVAPLADI, GonderimDurumu::belirle(true, '2020-01-01 00:00:00', $simdi));
        $this->assertSame(GonderimDurumu::SURE_DOLDU, GonderimDurumu::belirle(false, '2026-11-03 00:00:00', $simdi));
        $this->assertSame(GonderimDurumu::BEKLIYOR, GonderimDurumu::belirle(false, '2026-11-05 00:00:00', $simdi));
        $this->assertSame(GonderimDurumu::BEKLIYOR, GonderimDurumu::belirle(false, null, $simdi));
    }

    public function test_filtre_bilinmeyen_deger_tum(): void
    {
        $this->assertSame(GonderimFiltresi::TUM, GonderimFiltresi::dbden('x'));
        $this->assertSame(GonderimFiltresi::KOTU_PUAN, GonderimFiltresi::dbden('kotu_puan'));
    }

    public function test_esikler_varsayilan_ve_sinir(): void
    {
        $e = ReputasyonEsikleri::satirdan((object) []);
        $this->assertSame(9, $e->googleNps);
        $this->assertSame(2.5, $e->uyariCsat);
        $this->assertSame(10, ReputasyonEsikleri::npsSinirla(40));
        $this->assertSame(1.0, ReputasyonEsikleri::csatSinirla(0));
    }

    public function test_google_baglantisi_cozumleri(): void
    {
        $c = fn (string $g, ?callable $f = null) => GoogleYorumBaglantisi::coz($g, $f);

        $this->assertNull($c('  ')['review_url']);
        $this->assertSame('https://search.google.com/local/writereview?placeid=ChIJabcdefghijklmnopqrstuv', $c('ChIJabcdefghijklmnopqrstuv')['review_url']);
        $this->assertSame('ChIJabc', $c('https://x.com/?placeid=ChIJabc')['place_id']);
        $this->assertSame('https://g.page/r/AbC/review', $c('https://g.page/r/AbC')['review_url']);
        $this->assertSame('https://search.google.com/local/writereview?ftid=0x0:0xff', $c('https://www.google.com/maps?cid=255')['review_url']);

        $genis = $c('https://maps.app.goo.gl/kisa', fn () => 'https://www.google.com/maps/place/x!1s0x14:0xab!8m2');
        $this->assertSame('https://search.google.com/local/writereview?ftid=0x14:0xab', $genis['review_url']);

        $uyarili = $c('https://example.com/harita');
        $this->assertSame('https://example.com/harita', $uyarili['review_url']);
        $this->assertNotNull($uyarili['uyari']);

        $gecersiz = $c('javascript:alert(1)');
        $this->assertNull($gecersiz['review_url']);
        $this->assertSame(GoogleYorumBaglantisi::GECERSIZ, $gecersiz['uyari']);
    }
}
