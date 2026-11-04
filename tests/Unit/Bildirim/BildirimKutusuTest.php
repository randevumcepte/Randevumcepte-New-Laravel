<?php

namespace Tests\Unit\Bildirim;

use App\Domain\Bildirim\CihazSahibi;
use App\Exceptions\BulunamadiException;
use App\Services\Bildirim\BildirimKutusuFabrikasi;
use App\Services\Bildirim\BildirimSorgusu;
use App\Services\Bildirim\BildirimYazServisi;
use App\Services\Bildirim\CihazKayitServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Kutu izolasyonu + marka kapsamı + cihaz kaydı — izole sqlite, paylaşılan DB'ye dokunmaz. */
class BildirimKutusuTest extends TestCase
{
    private const LISTE = ['page' => 1, 'per_page' => 30, 'ara' => null];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'bildirim_sqlite',
            'database.connections.bildirim_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('bildirim_sqlite');
        DB::setDefaultConnection('bildirim_sqlite');

        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('yetkili_id');
            $t->unsignedBigInteger('salon_id');
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            $t->string('app_bundle')->nullable();
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('salon_id');
        });
        Schema::create('bildirimler', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('salon_id')->nullable();
            $t->unsignedBigInteger('personel_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('randevu_id')->nullable();
            $t->string('baslik')->nullable();
            $t->string('aciklama')->nullable();
            $t->string('url')->nullable();
            $t->string('img_src')->nullable();
            $t->string('tarih_saat')->nullable();
            $t->boolean('okundu')->default(false);
        });
        Schema::create('randevular', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('bildirim_kimlikleri', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('isletme_yetkili_id')->nullable();
            $t->unsignedBigInteger('salon_id')->nullable();
            $t->string('bildirim_id')->nullable();
            $t->string('cihaz')->nullable();
            $t->string('app_bundle')->nullable();
            $t->string('platform')->nullable();
            $t->string('token_tipi')->nullable();
            $t->string('kullanici_tipi')->nullable();
            $t->boolean('aktif')->default(true);
            $t->integer('gonderim_hatalari')->default(0);
            $t->timestamps();
        });

        DB::table('salon_personelleri')->insert([['id' => 10, 'yetkili_id' => 1, 'salon_id' => 100], ['id' => 20, 'yetkili_id' => 2, 'salon_id' => 100]]);
        DB::table('salonlar')->insert([['id' => 100, 'app_bundle' => 'com.marka.a'], ['id' => 101, 'app_bundle' => 'com.marka.a'], ['id' => 200, 'app_bundle' => 'com.marka.b']]);
        DB::table('randevular')->insert(['id' => 5, 'user_id' => 77]);
        DB::table('bildirimler')->insert([
            ['id' => 1, 'salon_id' => 100, 'personel_id' => 10, 'user_id' => null, 'randevu_id' => 5, 'aciklama' => 'benim', 'url' => '#'],
            ['id' => 2, 'salon_id' => 100, 'personel_id' => 20, 'user_id' => null, 'randevu_id' => null, 'aciklama' => 'baskasi', 'url' => '#'],
            ['id' => 3, 'salon_id' => 100, 'personel_id' => null, 'user_id' => 50, 'randevu_id' => null, 'aciklama' => 'musteri a', 'url' => '#'],
            ['id' => 4, 'salon_id' => 101, 'personel_id' => null, 'user_id' => 50, 'randevu_id' => null, 'aciklama' => 'musteri a2', 'url' => '#'],
            ['id' => 5, 'salon_id' => 200, 'personel_id' => null, 'user_id' => 50, 'randevu_id' => null, 'aciklama' => 'musteri b', 'url' => '#'],
            ['id' => 6, 'salon_id' => null, 'personel_id' => null, 'user_id' => 50, 'randevu_id' => null, 'aciklama' => 'salonsuz', 'url' => '#'],
        ]);
    }

    private function fabrika(): BildirimKutusuFabrikasi
    {
        return app(BildirimKutusuFabrikasi::class);
    }

    private function idler(array $sonuc): array
    {
        $idler = array_column($sonuc['data'], 'id');
        sort($idler);

        return $idler;
    }

    public function test_personel_yalniz_kendi_bildirimini_gorur_ve_hedef_randevu_musterisidir(): void
    {
        $sonuc = app(BildirimSorgusu::class)->listele($this->fabrika()->personel(1, 100), self::LISTE, false);

        $this->assertSame([1], $this->idler($sonuc));
        $this->assertSame(0, $sonuc['data'][0]['okundu']);
        $this->assertSame(77, $sonuc['data'][0]['hedef']['musteri_id']);
        $this->assertStringStartsWith('/v2/musteri/77', $sonuc['data'][0]['hedef']['web_yol']);
        $this->assertArrayNotHasKey('user_id', $sonuc['data'][0]);
    }

    public function test_personel_kaydi_yoksa_kutu_bos_kalir_musteri_satiri_sizmaz(): void
    {
        $kutu = $this->fabrika()->personel(999, 100);

        $this->assertSame([], app(BildirimSorgusu::class)->listele($kutu, self::LISTE, false)['data']);
        $this->assertSame(['okunmamis' => 0, 'toplam' => 0], app(BildirimSorgusu::class)->sayac($kutu));
    }

    public function test_baskasinin_bildirimi_okunamaz_ve_silinemez(): void
    {
        $kutu = $this->fabrika()->personel(1, 100);
        $yaz = app(BildirimYazServisi::class);

        foreach (['okundu', 'sil'] as $islem) {
            try {
                $yaz->$islem($kutu, 2);
                $this->fail('404 bekleniyordu: '.$islem);
            } catch (BulunamadiException) {
                $this->assertSame(0, (int) DB::table('bildirimler')->where('id', 2)->value('okundu'));
            }
        }
        $this->assertTrue(DB::table('bildirimler')->where('id', 2)->exists());
    }

    public function test_okundu_ve_toplu_silme_yalniz_kutuyu_etkiler(): void
    {
        $kutu = $this->fabrika()->personel(1, 100);
        $yaz = app(BildirimYazServisi::class);

        $this->assertSame(['okunmamis' => 0, 'toplam' => 1], $yaz->okundu($kutu, 1));
        $this->assertSame(['okunmamis' => 0, 'toplam' => 0], $yaz->tumunuSil($kutu));
        $this->assertSame(5, DB::table('bildirimler')->count());
    }

    public function test_musteri_marka_kapsami(): void
    {
        $sorgu = app(BildirimSorgusu::class);

        $marka = $this->fabrika()->musteri(50, 100, 'com.marka.a');
        $this->assertSame([3, 4, 6], $this->idler($sorgu->listele($marka, self::LISTE, false)));

        $anaSubeli = $this->fabrika()->musteri(50, 200, BildirimKutusuFabrikasi::ANA_UYGULAMA);
        $this->assertSame([5], $this->idler($sorgu->listele($anaSubeli, self::LISTE, false)));

        $ana = $this->fabrika()->musteri(50, null, BildirimKutusuFabrikasi::ANA_UYGULAMA);
        $this->assertSame([3, 4, 5, 6], $this->idler($sorgu->listele($ana, self::LISTE, false)));
    }

    public function test_musteri_toplu_okundu_baska_markaya_dokunmaz(): void
    {
        app(BildirimYazServisi::class)->tumunuOku($this->fabrika()->musteri(50, 100, 'com.marka.a'));

        $this->assertSame(1, (int) DB::table('bildirimler')->where('id', 3)->value('okundu'));
        $this->assertSame(0, (int) DB::table('bildirimler')->where('id', 5)->value('okundu'));
    }

    public function test_cihaz_token_tek_sahiplidir_ve_ayni_cihazin_eski_tokeni_silinir(): void
    {
        $cihaz = app(CihazKayitServisi::class);
        $musteri = CihazSahibi::musteri(50, null);

        $cihaz->kaydet($musteri, 'token-eski', 'ios', 'cihaz-1', 'com.marka.a');
        $id = $cihaz->kaydet($musteri, 'token-yeni', 'ios', 'cihaz-1', 'com.marka.a');
        $this->assertSame(['token-yeni'], DB::table('bildirim_kimlikleri')->pluck('bildirim_id')->all());

        // Aynı telefona personel girdi: satır el değiştirir, müşteri kolonu boşalır.
        $this->assertSame($id, $cihaz->kaydet(CihazSahibi::personel(10, 100, false), 'token-yeni', 'ios', 'cihaz-1', 'com.marka.a'));
        $satir = DB::table('bildirim_kimlikleri')->first();
        $this->assertNull($satir->user_id);
        $this->assertSame(10, (int) $satir->isletme_yetkili_id);
    }

    public function test_web_tokeni_salon_markasini_alir(): void
    {
        app(CihazKayitServisi::class)->kaydet(CihazSahibi::personel(10, 100, true), 'token-web-xxxxxxxxxxx', 'web', 'tarayici', 'Chrome / macOS');

        $this->assertSame('com.marka.a', DB::table('bildirim_kimlikleri')->value('app_bundle'));
    }

    public function test_pasiflestirme_yalniz_kendi_tokenini_etkiler(): void
    {
        $cihaz = app(CihazKayitServisi::class);
        $cihaz->kaydet(CihazSahibi::musteri(50, null), 'token-a', 'ios', null, null);

        $this->assertSame(0, $cihaz->pasiflestir(CihazSahibi::musteri(51, null), 'token-a'));
        $this->assertSame(1, (int) DB::table('bildirim_kimlikleri')->value('aktif'));
        $this->assertSame(1, $cihaz->pasiflestir(CihazSahibi::musteri(50, null), 'token-a'));
        $this->assertSame(0, (int) DB::table('bildirim_kimlikleri')->value('aktif'));
    }
}
