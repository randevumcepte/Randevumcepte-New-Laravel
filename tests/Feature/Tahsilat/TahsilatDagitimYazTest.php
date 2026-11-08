<?php

namespace Tests\Feature\Tahsilat;

use App\Models\AdisyonHizmetler;
use App\Models\AdisyonUrunler;
use App\Services\Tahsilat\TahsilatYazServisi;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Web ve mobilin ORTAK dagitim cekirdegi (kalemDagitimi, vadeDagitimi, planiYaz): kurus-kesin, kalanla sinirli.
 */
class TahsilatDagitimYazTest extends TestCase
{
    use TahsilatTestKurulumu;

    private TahsilatYazServisi $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tahsilatKur();
        $this->s = app(TahsilatYazServisi::class);
    }

    public function test_kalem_dagitimi_kurus_artigi_kaybolmaz(): void
    {
        foreach ([1, 2, 3] as $_) {
            $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);
        }
        $d = $this->s->kalemDagitimi(AdisyonHizmetler::all(), [], [], 10000, 0, 1);

        $this->assertSame([33.34, 33.33, 33.33], array_column($d['kalem_tahsilatlari'], 'tutar'));
        $this->assertSame(10000, $d['ozet']['dagitilan_kurus']);
        $this->assertTrue($d['ozet']['invariant_ok']);
    }

    public function test_kalem_dagitimi_kalan_borcu_asmaz(): void
    {
        $a = $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);
        $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);
        $this->odenmis('tahsilat_hizmetler', 'adisyon_hizmet_id', $a, '80.00');

        $d = $this->s->kalemDagitimi(AdisyonHizmetler::all(), [], [], 15000, 0, 1);

        // kalan 20 + 100 = 120; 150 odeme → 20 / 100, 30 TL dagitilmaz.
        $this->assertSame([20.0, 100.0], array_column($d['kalem_tahsilatlari'], 'tutar'));
        $this->assertSame(3000, $d['ozet']['dagitilmayan_kurus']);
        $this->assertTrue($d['ozet']['invariant_ok']);
    }

    public function test_harici_indirim_kurus_kesin_ve_kupon_dusulur(): void
    {
        $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);
        $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);
        $this->kalem('adisyon_urunler', ['fiyat' => '100.00']);
        DB::table('kampanya_katilimcilari')->insert(['indirim_kodu_adisyon_id' => 1, 'indirim_kodu_tutar' => '20.00']);

        $d = $this->s->kalemDagitimi(AdisyonHizmetler::all(), AdisyonUrunler::all(), [], 0, 12000, 1);

        // 120 - 20 kupon = 100 TL indirim, 3 kaleme 33.34 / 33.33 / 33.33 (eskiden 99.99).
        $this->assertSame([33.34, 33.33, 33.33], array_column($d['indirim_uygulamalari'], 'delta'));
        $this->assertCount(1, $d['kupon_dusulecek_ids']);
    }

    public function test_vade_odemesi_her_vadeye_kendi_tutari_ve_kalan_cap(): void
    {
        DB::table('taksit_vadeleri')->insert([['taksitli_tahsilat_id' => 7, 'tutar' => '150.00'], ['taksitli_tahsilat_id' => 7, 'tutar' => '150.00']]);
        $this->kalem('adisyon_hizmetler', ['fiyat' => '200.00', 'taksitli_tahsilat_id' => 7]);
        $this->kalem('adisyon_urunler', ['fiyat' => '100.00', 'taksitli_tahsilat_id' => 7]);

        $d = $this->s->vadeDagitimi([1, 2], [], 40000, 3);

        // 400 TL iki vadeye 150 + 150; plan kalani 300 → toplam 300 yazilir, 100 TL dagitilmaz. Eski mobil iki vadeye 400'u ayri ayri dagitirdi.
        $this->assertSame(30000, $d['ozet']['dagitilan_kurus']);
        $this->assertSame(['150', '150'], array_map(fn ($v) => (string) $v['paid_tutar'], $d['vade_guncellemeleri']));
        $this->assertSame([100.0, 50.0, 100.0, 50.0], array_column($d['kalem_tahsilatlari'], 'tutar'));
    }

    public function test_ayni_planin_vadeleri_kalani_birlikte_asamaz(): void
    {
        DB::table('taksit_vadeleri')->insert([['taksitli_tahsilat_id' => 7, 'tutar' => '100.00'], ['taksitli_tahsilat_id' => 7, 'tutar' => '100.00']]);
        $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00', 'taksitli_tahsilat_id' => 7]);
        $this->kalem('adisyon_urunler', ['fiyat' => '50.00', 'taksitli_tahsilat_id' => 7]);

        $d = $this->s->vadeDagitimi([1, 2], [], 20000, null);

        // Plan kalani 150: ilk vade 66.67 / 33.33, ikinci vade kalan 33.33 / 16.67 ile sinirli → toplam 150 (eskiden 200 yazilirdi).
        $this->assertSame([66.67, 33.33, 33.33, 16.67], array_column($d['kalem_tahsilatlari'], 'tutar'));
        $this->assertSame(15000, $d['ozet']['dagitilan_kurus']);
    }

    public function test_plani_yaz_kismi_vadeyi_boler_ve_satirlari_yazar(): void
    {
        DB::table('taksit_vadeleri')->insert(['taksitli_tahsilat_id' => 7, 'tutar' => '100.00']);
        $h = $this->kalem('adisyon_hizmetler', ['fiyat' => '200.00', 'taksitli_tahsilat_id' => 7]);
        $u = $this->kalem('adisyon_urunler', ['fiyat' => '100.00', 'taksitli_tahsilat_id' => 7]);

        $this->s->planiYaz($this->s->vadeDagitimi([1], [], 5000, 2), 55);

        $this->assertSame([$h => 33.33], $this->yazilan('tahsilat_hizmetler', 'adisyon_hizmet_id', 55));
        $this->assertSame([$u => 16.67], $this->yazilan('tahsilat_urunler', 'adisyon_urun_id', 55));
        $vadeler = DB::table('taksit_vadeleri')->orderBy('id')->get();
        $this->assertSame([50.0, 50.0], [(float) $vadeler[0]->tutar, (float) $vadeler[1]->tutar]);
        $this->assertSame([1, 0], [(int) $vadeler[0]->odendi, (int) $vadeler[1]->odendi]);
        $this->assertSame(2, (int) $vadeler[0]->odeme_yontemi_id);
    }

    public function test_plani_yaz_ek_kolonlari_satira_yazar(): void
    {
        $u = $this->kalem('adisyon_urunler', ['fiyat' => '99.99']);

        $this->s->planiYaz($this->s->kalemDagitimi([], AdisyonUrunler::all(), [], 9999, 0, 1), 8, ['salon_id' => 20, 'user_id' => 5]);

        $satir = DB::table('tahsilat_urunler')->where('tahsilat_id', 8)->first();
        $this->assertSame([$u, 99.99, 20, 5], [(int) $satir->adisyon_urun_id, (float) $satir->tutar, (int) $satir->salon_id, (int) $satir->user_id]);
    }
}
