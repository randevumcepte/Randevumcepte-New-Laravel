<?php

namespace Tests\Feature\Online;

use App\Services\MusteriHesap\MusteriCarkServisi;
use App\Services\Online\OnlineSalonKapisi;
use App\Services\Online\OnlineSecenekServisi;
use App\Services\Online\VitrinServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Salon sitesi SEO: /sitemap.xml, /robots.txt, eski adres biçimleri (/{ad}-{id}, arama terimi sayfası, personel)
 * yalnız salonun kendi alan adında; merkezi /salonlar-sitemap.xml ve /salonlar-dizini panel host'unda. Izole sqlite;
 * vitrin verisi sabit (VitrinServisi::vitrin/yorumlar taklit), geri kalanı gerçek servis.
 */
class SiteSeoTest extends TestCase
{
    private const SALON = 7;

    private const HOST = 'http://lotus-guzellik.test';

    private const PANEL = 'http://app.randevumcepte.test';

    private array $terimler = ['lazer epilasyon bornova'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'seo_sqlite',
            'database.connections.seo_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('seo_sqlite');
        DB::setDefaultConnection('seo_sqlite');
        $this->semaKur();
        $this->veriKur();

        $vitrin = Mockery::mock(VitrinServisi::class.'[vitrin,yorumlar]', [
            $this->app->make(OnlineSalonKapisi::class), $this->app->make(OnlineSecenekServisi::class), $this->app->make(MusteriCarkServisi::class),
        ]);
        $vitrin->shouldReceive('vitrin')->andReturnUsing(fn () => $this->vitrinVerisi());
        $vitrin->shouldReceive('yorumlar')->andReturn(['yorumlar' => []]);
        $this->app->instance(VitrinServisi::class, $vitrin);
    }

    public function test_salon_sitemap_yalniz_kendi_alan_adinda(): void
    {
        $this->get(self::HOST.'/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>https://lotus-guzellik.test/</loc>', false)
            ->assertSee('<lastmod>2026-10-01</lastmod>', false);

        $this->get(self::PANEL.'/sitemap.xml')->assertNotFound();
    }

    public function test_robots_salon_ve_panel_host(): void
    {
        $salon = $this->get(self::HOST.'/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Sitemap: https://lotus-guzellik.test/sitemap.xml', $salon);
        $this->assertStringContainsString('Disallow: /randevularim', $salon);

        $panel = $this->get(self::PANEL.'/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Sitemap: https://app.randevumcepte.test/salonlar-sitemap.xml', $panel);
        $this->assertStringContainsString('Disallow: /v2/', $panel);
    }

    public function test_merkezi_index_ve_dizin_yalniz_lisansi_suren_salonlar(): void
    {
        $this->get(self::PANEL.'/salonlar-sitemap.xml')->assertOk()
            ->assertSee('<loc>https://lotus-guzellik.test/sitemap.xml</loc>', false)
            ->assertDontSee('suresi-bitmis.test', false)
            ->assertDontSee('tarihsiz.test', false);

        $this->get(self::PANEL.'/salonlar-dizini')->assertOk()
            ->assertSee('Lotus Güzellik')->assertSee('Bornova / İzmir')->assertDontSee('Süresi Bitmiş');

        $this->get(self::HOST.'/salonlar-sitemap.xml')->assertNotFound();
        $this->get(self::HOST.'/salonlar-dizini')->assertNotFound();
    }

    public function test_arama_terimi_sayfasi_baslik_h1_canonical_ve_ziyaret(): void
    {
        $yol = '/guzellik-salonu/izmir/bornova/'.self::SALON.'/lotus-guzellik/cilt-bakimi/12';

        $this->get(self::HOST.$yol)->assertOk()
            ->assertSee('<title>Cilt Bakımı Karşıyaka | Lotus Güzellik</title>', false)
            ->assertSee('<h1 class="slp-hero__title">Cilt Bakımı Karşıyaka</h1>', false)
            ->assertSee('<link rel="canonical" href="https://lotus-guzellik.test">', false)
            ->assertSee('<meta name="googlebot" content="index, follow">', false)
            ->assertSee('<link rel="sitemap" type="application/xml" title="Sitemap" href="/sitemap.xml">', false)
            ->assertSee('"openingHoursSpecification"', false)
            ->assertSee('"aggregateRating"', false);
        $this->assertSame(12, (int) DB::table('isletme_ziyaretciler')->value('arama_terimi_id'));

        // Başka salonun terimi, başka salonun id'si, salon olmayan host: 404.
        $this->get(self::HOST.'/guzellik-salonu/izmir/bornova/'.self::SALON.'/lotus-guzellik/x/99')->assertNotFound();
        $this->get(self::HOST.'/guzellik-salonu/izmir/bornova/8/lotus-guzellik/cilt-bakimi/12')->assertNotFound();
        $this->get(self::PANEL.$yol)->assertNotFound();
    }

    public function test_isletme_adi_id_sayfasi_ve_bos_terimde_anlamli_baslik(): void
    {
        $this->terimler = [];

        $this->get(self::HOST.'/lotus-guzellik-'.self::SALON)->assertOk()
            ->assertSee('<title>Lotus Güzellik | Güzellik Salonu - Bornova/İzmir</title>', false)
            ->assertSee('<meta name="description" content="Lotus Güzellik (Güzellik Salonu, Bornova/İzmir). Hizmetleri', false)
            ->assertSee('<h1 class="slp-hero__title">Lotus Güzellik</h1>', false);

        $this->get(self::HOST.'/lotus-guzellik-8')->assertNotFound();
        $this->get(self::PANEL.'/lotus-guzellik-'.self::SALON)->assertNotFound();
    }

    public function test_kokte_ilk_arama_terimi_basliga_girer(): void
    {
        $this->get(self::HOST.'/')->assertOk()
            ->assertSee('<title>Lazer Epilasyon Bornova | Lotus Güzellik</title>', false)
            ->assertSee('<h1 class="slp-hero__title">Lotus Güzellik</h1>', false);
    }

    public function test_suresi_bitmis_salonun_sitesi_hizmet_disi(): void
    {
        $this->get('http://suresi-bitmis.test/')->assertStatus(503)
            ->assertSee('Web sitemiz hizmet dışıdır')
            ->assertHeader('X-Rc-Kasitli', '1');
        $this->get('http://suresi-bitmis.test/giris')->assertStatus(503);
    }

    public function test_onizleme_indekslenmez(): void
    {
        $this->get(self::PANEL.'/site/'.self::SALON)->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('name="googlebot"', false)
            ->assertDontSee('rel="sitemap"', false);
    }

    public function test_online_randevuda_yalniz_aktif_takvimde_ve_arsivsiz_personel_web_ve_mobilde(): void
    {
        DB::table('salon_personelleri')->insert(['id' => 6, 'salon_id' => self::SALON, 'aktif' => 1, 'takvimde_gorunsun' => 1, 'arsivli' => 1, 'personel_adi' => 'Arşivli']);
        DB::table('salon_personelleri')->insert(['id' => 7, 'salon_id' => self::SALON, 'aktif' => 0, 'takvimde_gorunsun' => 1, 'personel_adi' => 'Pasif']);
        $s = app(\App\Services\Online\OnlineSecenekServisi::class);

        foreach ([false, true] as $mobil) {
            $this->assertSame([3, 4], array_column($s->personeller(self::SALON, $mobil), 'id'), $mobil ? 'mobil' : 'web');
        }
        $this->assertSame([3, 4], $s->uygunPersonelIdleri(self::SALON, 99), 'hizmete atanan yoksa uygun personel');
    }

    public function test_personel_sayfasi(): void
    {
        $this->get(self::HOST.'/lotus-guzellik-'.self::SALON.'/personel/3')->assertOk()
            ->assertSee('<title>Ayşe Yılmaz | Lotus Güzellik</title>', false)
            ->assertSee('<h1 class="perdet-ad">Ayşe Yılmaz</h1>', false)
            ->assertSee('<link rel="canonical" href="https://lotus-guzellik.test/lotus-guzellik-7/personel/3">', false)
            ->assertSee('Cilt Bakımı')
            ->assertSee('/lotus-guzellik-7/personel/4', false)
            ->assertDontSee('vitrin-randevu.js', false);

        $this->get(self::HOST.'/lotus-guzellik-'.self::SALON.'/personel/5')->assertNotFound(); // takvimde gizli
        $this->get(self::HOST.'/lotus-guzellik-'.self::SALON.'/personel/999')->assertNotFound();
        $this->get(self::PANEL.'/lotus-guzellik-'.self::SALON.'/personel/3')->assertNotFound();
    }

    private function vitrinVerisi(): array
    {
        return [
            'salon' => [
                'id' => self::SALON, 'salon_adi' => 'Lotus Güzellik', 'domain' => 'lotus-guzellik.test', 'app_bundle' => null,
                'lisans_aktif' => true, 'uyelik_bitis_tarihi' => null, 'musteri_online_randevu_aktif' => true, 'studyo_modu' => false,
                'grup_dersi_aktif' => false, 'randevu_saat_araligi' => 30, 'randevu_takvim_turu' => null,
                'aciklama' => null, 'meta_description' => null, 'adres' => 'Kazımdirik Mah. 1', 'telefon_1' => '5321112233',
                'instagram_sayfa' => '@lotus', 'facebook_sayfa' => null, 'maps_iframe' => null, 'logo' => null, 'tema_seed' => null,
                'salon_turu_adi' => 'Güzellik Salonu', 'il_adi' => 'İzmir', 'ilce_adi' => 'Bornova',
            ],
            'calisma_saatleri' => [
                ['haftanin_gunu' => 1, 'calisiyor' => true, 'baslangic_saati' => '09:00', 'bitis_saati' => '19:00'],
                ['haftanin_gunu' => 7, 'calisiyor' => false, 'baslangic_saati' => null, 'bitis_saati' => null],
            ],
            'kategoriler' => [], 'hizmetler' => [], 'personeller' => [], 'galeri' => [], 'kapak_gorseli' => null,
            'arama_terimleri' => $this->terimler,
            'puan' => ['ortalama' => 4.8, 'toplam_puan' => 5, 'toplam_yorum' => 3, 'dagilim' => ['1' => 0, '2' => 0, '3' => 0, '4' => 1, '5' => 4]],
            'one_cikan_yorumlar' => [], 'cark' => null,
        ];
    }

    private function semaKur(): void
    {
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            foreach (['salon_adi', 'domain', 'uyelik_bitis_tarihi', 'logo', 'tema_seed'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('il_id')->nullable();
            $t->integer('ilce_id')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('il', fn (Blueprint $t) => [$t->id(), $t->string('il_adi')]);
        Schema::create('ilce', fn (Blueprint $t) => [$t->id(), $t->string('ilce_adi')]);
        Schema::create('arama_terimleri', fn (Blueprint $t) => [$t->id(), $t->integer('salon_id'), $t->string('arama_terimi')]);
        Schema::create('isletme_ziyaretciler', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            foreach (['ipadres', 'browser'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('arama_terimi_id')->nullable();
            $t->timestamps();
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            foreach (['aktif', 'takvimde_gorunsun', 'takvim_sirasi', 'cinsiyet', 'yetkili_id', 'arsivli'] as $k) {
                $t->integer($k)->nullable();
            }
            foreach (['personel_adi', 'unvan', 'uzmanlik', 'yillik_tecrube', 'instagram', 'aciklama'] as $k) {
                $t->string($k)->nullable();
            }
        });
        Schema::create('isletmeyetkilileri', fn (Blueprint $t) => [$t->id(), $t->string('name')->nullable(), $t->string('profil_resim')->nullable()]);
        Schema::create('personel_sunulan_hizmetler', fn (Blueprint $t) => [$t->id(), $t->integer('personel_id'), $t->integer('hizmet_id')]);
        Schema::create('hizmetler', fn (Blueprint $t) => [$t->id(), $t->string('hizmet_adi')]);
    }

    private function veriKur(): void
    {
        DB::table('il')->insert(['id' => 35, 'il_adi' => 'İzmir']);
        DB::table('ilce')->insert(['id' => 1, 'ilce_adi' => 'Bornova']);
        DB::table('salonlar')->insert([
            ['id' => self::SALON, 'salon_adi' => 'Lotus Güzellik', 'domain' => 'lotus-guzellik.test', 'uyelik_bitis_tarihi' => now()->addMonth()->toDateString(), 'il_id' => 35, 'ilce_id' => 1, 'updated_at' => '2026-10-01 10:00:00'],
            ['id' => 8, 'salon_adi' => 'Süresi Bitmiş', 'domain' => 'suresi-bitmis.test', 'uyelik_bitis_tarihi' => now()->subDay()->toDateString(), 'il_id' => null, 'ilce_id' => null, 'updated_at' => null],
            ['id' => 9, 'salon_adi' => 'Tarihsiz', 'domain' => 'tarihsiz.test', 'uyelik_bitis_tarihi' => null, 'il_id' => null, 'ilce_id' => null, 'updated_at' => null],
        ]);
        DB::table('arama_terimleri')->insert([
            ['id' => 12, 'salon_id' => self::SALON, 'arama_terimi' => 'cilt bakımı karşıyaka'],
            ['id' => 99, 'salon_id' => 8, 'arama_terimi' => 'baska salon'],
        ]);
        DB::table('isletmeyetkilileri')->insert(['id' => 1, 'name' => 'Ayşe Yılmaz', 'profil_resim' => null]);
        DB::table('salon_personelleri')->insert([
            ['id' => 3, 'salon_id' => self::SALON, 'aktif' => 1, 'takvimde_gorunsun' => 1, 'takvim_sirasi' => 1, 'cinsiyet' => 0, 'yetkili_id' => 1, 'personel_adi' => 'Ayşe', 'unvan' => 'Uzman', 'uzmanlik' => null, 'yillik_tecrube' => '5', 'instagram' => null, 'aciklama' => null],
            ['id' => 4, 'salon_id' => self::SALON, 'aktif' => 1, 'takvimde_gorunsun' => 1, 'takvim_sirasi' => 2, 'cinsiyet' => 1, 'yetkili_id' => null, 'personel_adi' => 'Mehmet', 'unvan' => null, 'uzmanlik' => null, 'yillik_tecrube' => null, 'instagram' => null, 'aciklama' => null],
            ['id' => 5, 'salon_id' => self::SALON, 'aktif' => 1, 'takvimde_gorunsun' => 0, 'takvim_sirasi' => 3, 'cinsiyet' => 0, 'yetkili_id' => null, 'personel_adi' => 'Gizli', 'unvan' => null, 'uzmanlik' => null, 'yillik_tecrube' => null, 'instagram' => null, 'aciklama' => null],
        ]);
        DB::table('hizmetler')->insert(['id' => 1, 'hizmet_adi' => 'Cilt Bakımı']);
        DB::table('personel_sunulan_hizmetler')->insert(['personel_id' => 3, 'hizmet_id' => 1]);
    }
}
