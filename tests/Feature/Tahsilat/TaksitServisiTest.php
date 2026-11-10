<?php

namespace Tests\Feature\Tahsilat;

use App\Exceptions\GecersizIslemException;
use App\Services\Tahsilat\AlacakServisi;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Services\Tahsilat\TaksitServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** "Taksit Yap": ön ödeme normal tahsilat dağıtımıyla yazılır, kalan borç kuruşu kuruşuna vadelere bölünür. */
class TaksitServisiTest extends TestCase
{
    use TahsilatTestKurulumu;

    private TaksitServisi $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tahsilatKur();
        Schema::create('adisyonlar', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id')->nullable();
            $t->integer('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('tahsilatlar', function (Blueprint $t) {
            $t->id();
            foreach (['adisyon_id', 'user_id', 'olusturan_id', 'salon_id', 'odeme_yontemi_id', 'para_girisi'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->decimal('tutar', 10, 2)->nullable();
            $t->decimal('yapilan_odeme', 10, 2)->nullable();
            $t->date('odeme_tarihi')->nullable();
            $t->string('notlar')->nullable();
            $t->timestamps();
        });
        Schema::create('api_idempotency_anahtarlari', function (Blueprint $t) {
            $t->id();
            $t->string('anahtar');
            $t->string('uc');
            $t->integer('yetkili_id')->nullable();
            $t->integer('salon_id')->nullable();
            $t->integer('yanit_kodu')->nullable();
            $t->text('yanit_json')->nullable();
            $t->timestamps();
            $t->unique(['anahtar', 'uc']);
        });
        DB::table('adisyonlar')->insert(['id' => 1, 'salon_id' => 20, 'user_id' => 5]);
        $this->s = app(TaksitServisi::class);
    }

    private function taksit(array $g): array
    {
        return $this->s->adisyonTaksiti(1, $g + ['vade' => 3, 'vade_baslangic_tarihi' => '2026-01-31', 'on_odeme_yontemi_id' => 2,
            'odeme_tarihi' => '2026-01-15', 'idempotency_key' => 'k1'], ['yetkili_id' => 9, 'salon_id' => 20, 'olusturan_id' => 3]);
    }

    public function test_on_odeme_tahsil_edilir_kalan_kurusu_kurusuna_taksitlenir(): void
    {
        $h = $this->kalem('adisyon_hizmetler', ['fiyat' => '700.00']);
        $u = $this->kalem('adisyon_urunler', ['fiyat' => '300.00']);

        $r = $this->taksit(['on_odeme' => 0.01]);

        $this->assertSame(999.99, $r['taksit_tutari']);
        $t = DB::table('tahsilatlar')->first();
        $this->assertSame([0.01, 2], [(float) $t->tutar, (int) $t->odeme_yontemi_id]);
        $vadeler = DB::table('taksit_vadeleri')->orderBy('id')->get();
        $this->assertSame([333.33, 333.33, 333.33], $vadeler->map(fn ($v) => (float) $v->tutar)->all());
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $vadeler->pluck('vade_tarih')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertSame(3, DB::table('alacaklar')->where('adisyon_id', 1)->where('taksitli_tahsilat_id', $r['taksitli_tahsilat_id'])->count());
        $this->assertSame($r['taksitli_tahsilat_id'], (int) DB::table('adisyon_hizmetler')->where('id', $h)->value('taksitli_tahsilat_id'));
        $this->assertSame($r['taksitli_tahsilat_id'], (int) DB::table('adisyon_urunler')->where('id', $u)->value('taksitli_tahsilat_id'));
        $this->assertSame([], app(TahsilatYazServisi::class)->acikKalanlar(1));
    }

    public function test_artik_kurus_ilk_vadelere_ve_on_odemesiz_taksit(): void
    {
        $this->kalem('adisyon_hizmetler', ['fiyat' => '1000.00']);

        $r = $this->taksit(['on_odeme' => 0]);

        $this->assertNull($r['tahsilat_id']);
        $this->assertSame(0, DB::table('tahsilatlar')->count());
        $this->assertSame([333.34, 333.33, 333.33], DB::table('taksit_vadeleri')->orderBy('id')->pluck('tutar')->map(fn ($v) => (float) $v)->all());
    }

    public function test_ayni_anahtar_ikinci_plan_acmaz(): void
    {
        $this->kalem('adisyon_hizmetler', ['fiyat' => '600.00']);

        $this->taksit(['on_odeme' => 100]);
        $ikinci = $this->taksit(['on_odeme' => 100]);

        $this->assertTrue($ikinci['replay']);
        $this->assertSame(1, DB::table('taksitli_tahsilatlar')->count());
        $this->assertSame(1, DB::table('tahsilatlar')->count());
    }

    public function test_on_odeme_kalanin_tamamini_karsilarsa_reddedilir(): void
    {
        $this->kalem('adisyon_hizmetler', ['fiyat' => '100.00']);

        $this->expectException(GecersizIslemException::class);
        $this->taksit(['on_odeme' => 100]);
    }

    public function test_kismi_vade_odemesi_alacagi_boler_ve_odenen_listeden_duser(): void
    {
        $this->kalem('adisyon_hizmetler', ['fiyat' => '300.00']);
        $this->taksit(['on_odeme' => 0]);
        $ilkVade = (int) DB::table('taksit_vadeleri')->orderBy('id')->value('id');

        $y = app(TahsilatYazServisi::class);
        $y->planiYaz($y->vadeDagitimi([$ilkVade], [], 4000, 1), 77);

        $liste = app(AlacakServisi::class)->listele(1);
        $this->assertSame([60.0, 100.0, 100.0], array_map(fn ($a) => (float) $a['tutar'], $liste));
        $this->assertSame(40.0, (float) DB::table('alacaklar')->where('taksit_vade_id', $ilkVade)->value('tutar'));
    }

    public function test_taksit_alacagi_tek_basina_silinemez(): void
    {
        $this->kalem('adisyon_hizmetler', ['fiyat' => '300.00']);
        $this->taksit(['on_odeme' => 0]);

        $this->expectException(GecersizIslemException::class);
        app(AlacakServisi::class)->sil((int) DB::table('alacaklar')->value('id'));
    }
}
