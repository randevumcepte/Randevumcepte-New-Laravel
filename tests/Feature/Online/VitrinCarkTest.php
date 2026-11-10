<?php

namespace Tests\Feature\Online;

use App\Services\MusteriHesap\MusteriCarkServisi;
use App\Services\MusteriHesap\MusteriProfilServisi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Oturum\PlatformMesaj;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Vitrin hediye carki, misafir yolu: cevir (hicbir sey yazilmaz) → kod (telefona) → dogrula (hesap + odul, uye
 * cevirmesiyle ayni yazim). Taslak tek kullanimlik; IP ve numara (aralik) siniri. Izole sqlite.
 */
class VitrinCarkTest extends TestCase
{
    private const SALON = 1;

    private const TEL = '5321112233';

    /** @var array<int,array{tel:string,mesaj:string}> */
    private array $mesajlar = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'cark_sqlite',
            'database.connections.cark_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'online.cark_misafir_ip' => 3,
        ]);
        DB::purge('cark_sqlite');
        DB::setDefaultConnection('cark_sqlite');
        $this->semaKur();

        $mesaj = Mockery::mock(PlatformMesaj::class);
        $mesaj->shouldReceive('kod')->andReturnUsing(function ($tel, $metin) {
            $this->mesajlar[] = ['tel' => $tel, 'mesaj' => $metin];

            return true;
        });
        $this->app->instance(PlatformMesaj::class, $mesaj);

        $token = Mockery::mock(ErisimTokenServisi::class);
        $token->shouldReceive('uret')->andReturn('test-token');
        $this->app->instance(ErisimTokenServisi::class, $token);

        $profil = Mockery::mock(MusteriProfilServisi::class);
        $profil->shouldReceive('goster')->andReturnUsing(fn ($id) => ['id' => $id]);
        $this->app->instance(MusteriProfilServisi::class, $profil);
    }

    private function semaKur(): void
    {
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            foreach (['salon_adi', 'app_bundle', 'domain'] as $k) {
                $t->string($k)->nullable();
            }
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'cep_telefon', 'password'] as $k) {
                $t->string($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('musteri_portfoy', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('user_id');
            $t->integer('aktif')->default(1);
            foreach (['tur', 'kara_liste', 'kvkk_onay_alindi', 'onay_kodu'] as $k) {
                $t->string($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('carkifelek_sistemi', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('aktifmi')->default(1);
            foreach (['cevirme_araligi_gun', 'kupon_cark_gecerlilik_gun', 'kupon_puan_gecerlilik_gun'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->text('gecerli_salonlar')->nullable();
        });
        Schema::create('carkifelek_dilimleri', function (Blueprint $t) {
            $t->id();
            $t->integer('cark_id');
            foreach (['dilim_ismi', 'renk_kodu', 'tip', 'indirim_tipi'] as $k) {
                $t->string($k)->nullable();
            }
            $t->decimal('deger', 10, 2)->nullable();
            $t->integer('dilim_olasilik')->default(0);
            $t->integer('sira')->default(0);
        });
        Schema::create('carkifelek_cevirme_loglari', function (Blueprint $t) {
            $t->id();
            foreach (['cark_id', 'salon_id', 'user_id', 'randevu_id', 'dilim_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->string('tip')->nullable();
            $t->decimal('deger', 10, 2)->nullable();
            $t->string('dilim_ismi')->nullable();
            $t->timestamps();
        });
        Schema::create('carkifelek_odulleri', function (Blueprint $t) {
            $t->id();
            foreach (['log_id', 'salon_id', 'user_id', 'kullanildi'] as $k) {
                $t->integer($k)->nullable();
            }
            foreach (['kod', 'tip', 'baslik', 'indirim_tipi'] as $k) {
                $t->string($k)->nullable();
            }
            $t->decimal('deger', 10, 2)->nullable();
            $t->date('gecerlilik_tarihi')->nullable();
            $t->dateTime('kullanim_tarihi')->nullable();
            $t->text('gecerli_salonlar')->nullable();
            $t->timestamps();
        });
        Schema::create('salon_sadakat_puanlari', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('user_id');
            $t->decimal('puan', 10, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('bildirimler', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id')->nullable();
            foreach (['aciklama', 'url', 'tarih_saat'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('okundu')->default(0);
            $t->timestamps();
        });

        DB::table('salonlar')->insert(['id' => self::SALON, 'salon_adi' => 'Test Salon']);
        DB::table('carkifelek_sistemi')->insert(['id' => 1, 'salon_id' => self::SALON, 'aktifmi' => 1, 'cevirme_araligi_gun' => 1, 'kupon_cark_gecerlilik_gun' => 30]);
    }

    /** Garantili hediye kurgusu: tek dilim olasilik 100 (kazanan), digeri 0. */
    private function dilimler(string $kazananTip, float $deger): void
    {
        DB::table('carkifelek_dilimleri')->insert([
            ['id' => 11, 'cark_id' => 1, 'dilim_ismi' => '', 'renk_kodu' => '#ff0000', 'tip' => 'bos', 'indirim_tipi' => 'yuzde', 'deger' => null, 'dilim_olasilik' => 0, 'sira' => 1],
            ['id' => 12, 'cark_id' => 1, 'dilim_ismi' => '', 'renk_kodu' => '#00ff00', 'tip' => $kazananTip, 'indirim_tipi' => 'yuzde', 'deger' => $deger, 'dilim_olasilik' => 100, 'sira' => 2],
        ]);
    }

    private function cevir(): array
    {
        return $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/cevir')->assertOk()->json('veri');
    }

    private function kodIste(string $taslak, string $tel = self::TEL)
    {
        return $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/kod', [
            'taslak' => $taslak, 'ad' => 'Ayşe', 'soyad' => 'Yılmaz', 'cep_telefon' => '0'.$tel, 'kvkk' => true, 'form_sure_sn' => 10,
        ]);
    }

    private function sonKod(): string
    {
        preg_match('/(\d{4})\s*$/', (string) end($this->mesajlar)['mesaj'], $m);

        return $m[1];
    }

    public function test_vitrin_carki_olasilik_ve_kazanan_vermez(): void
    {
        $this->dilimler('hizmet_indirimi', 20);

        $cark = app(MusteriCarkServisi::class)->vitrinCarki(self::SALON);

        $this->assertCount(2, $cark['dilimler']);
        foreach ($cark['dilimler'] as $d) {
            $this->assertArrayNotHasKey('dilim_olasilik', $d);
        }
        $this->assertSame('%20', $cark['dilimler'][1]['deger_metni']);
        $this->assertSame('%20 Hizmet İnd.', $cark['dilimler'][1]['tam_etiket']);
    }

    public function test_misafir_cevirir_kod_dogrular_kupon_yazilir_ve_yeni_hesaba_oturum_verilir(): void
    {
        $this->dilimler('hizmet_indirimi', 20);

        $cevir = $this->cevir();
        $this->assertSame(1, $cevir['dilimIndex']);
        $this->assertTrue($cevir['kayitGerekli']);
        $this->assertNotEmpty($cevir['taslak']);
        $this->assertNull($cevir['odulKodu']);
        $this->assertSame(0, DB::table('carkifelek_cevirme_loglari')->count(), 'misafir cevirmesi kayit yazmaz');

        $this->kodIste($cevir['taslak'])->assertOk()->assertJsonPath('veri.telefon', self::TEL);
        $this->assertSame(self::TEL, $this->mesajlar[0]['tel']);
        $this->assertStringContainsString('Test Salon çarkıfelek doğrulama kodunuz', $this->mesajlar[0]['mesaj']);

        $sonuc = $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/dogrula', ['taslak' => $cevir['taslak'], 'kod' => $this->sonKod()])
            ->assertOk()->json('veri');

        $userId = (int) DB::table('users')->where('cep_telefon', self::TEL)->value('id');
        $this->assertGreaterThan(0, $userId);
        $this->assertSame('Ayşe Yılmaz', DB::table('users')->where('id', $userId)->value('name'));
        $this->assertTrue($sonuc['yeniUye']);
        $this->assertSame('test-token', $sonuc['oturum']['token']);
        $this->assertTrue(DB::table('musteri_portfoy')->where('salon_id', self::SALON)->where('user_id', $userId)->exists());

        $log = DB::table('carkifelek_cevirme_loglari')->first();
        $this->assertSame($userId, (int) $log->user_id);
        $this->assertSame(12, (int) $log->dilim_id);
        $kupon = DB::table('carkifelek_odulleri')->first();
        $this->assertSame($sonuc['odulKodu'], $kupon->kod);
        $this->assertSame($userId, (int) $kupon->user_id);
        $this->assertSame((int) $log->id, (int) $kupon->log_id);
        $this->assertSame(1, DB::table('bildirimler')->where('salon_id', self::SALON)->count());
    }

    public function test_taslak_ikinci_kez_kullanilamaz(): void
    {
        $this->dilimler('hizmet_indirimi', 20);
        $cevir = $this->cevir();
        $this->kodIste($cevir['taslak'])->assertOk();
        $kod = $this->sonKod();
        $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/dogrula', ['taslak' => $cevir['taslak'], 'kod' => $kod])->assertOk();

        $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/dogrula', ['taslak' => $cevir['taslak'], 'kod' => $kod])
            ->assertStatus(422)->assertJsonPath('basarili', false);
        $this->kodIste($cevir['taslak'], '5324445566')->assertStatus(422);

        $this->assertSame(1, DB::table('carkifelek_odulleri')->count());
        $this->assertSame(1, DB::table('carkifelek_cevirme_loglari')->count());
    }

    public function test_yanlis_kod_hicbir_sey_yazmaz(): void
    {
        $this->dilimler('puan', 50);
        $cevir = $this->cevir();
        $this->kodIste($cevir['taslak'])->assertOk();
        $yanlis = $this->sonKod() === '0000' ? '1111' : '0000';

        $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/dogrula', ['taslak' => $cevir['taslak'], 'kod' => $yanlis])->assertStatus(422);

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('salon_sadakat_puanlari')->count());
        $this->assertSame(0, DB::table('carkifelek_cevirme_loglari')->count());
    }

    public function test_kayitli_numaraya_puan_yazilir_ve_kodla_oturum_acilir(): void
    {
        $this->dilimler('puan', 50);
        $mevcut = (int) DB::table('users')->insertGetId(['name' => 'Eski Müşteri', 'cep_telefon' => self::TEL]);
        $cevir = $this->cevir();
        $this->kodIste($cevir['taslak'])->assertOk();

        $sonuc = $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/dogrula', ['taslak' => $cevir['taslak'], 'kod' => $this->sonKod()])
            ->assertOk()->json('veri');

        $this->assertFalse($sonuc['yeniUye']);
        $this->assertSame('test-token', $sonuc['oturum']['token']);
        $this->assertNull($sonuc['odulKodu']);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame('Eski Müşteri', DB::table('users')->where('id', $mevcut)->value('name'));
        $this->assertEquals(50, (float) DB::table('salon_sadakat_puanlari')->where('user_id', $mevcut)->value('puan'));
        $this->assertSame(0, DB::table('bildirimler')->count(), 'yalniz yeni musteri bildirilir');
    }

    public function test_numara_araligi_dolmadan_ikinci_hediye_alamaz(): void
    {
        $this->dilimler('puan', 50);
        $mevcut = (int) DB::table('users')->insertGetId(['name' => 'Eski Müşteri', 'cep_telefon' => self::TEL]);
        DB::table('carkifelek_cevirme_loglari')->insert([
            'cark_id' => 1, 'salon_id' => self::SALON, 'user_id' => $mevcut, 'dilim_id' => 12, 'tip' => 'puan', 'deger' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cevir = $this->cevir();
        $this->kodIste($cevir['taslak'])->assertOk();

        $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/dogrula', ['taslak' => $cevir['taslak'], 'kod' => $this->sonKod()])
            ->assertStatus(422)->assertJsonPath('mesaj', 'Bugün çarkı çevirdiniz. Yarın tekrar deneyebilirsiniz.');

        $this->assertSame(1, DB::table('carkifelek_cevirme_loglari')->count());
        $this->assertSame(0, DB::table('salon_sadakat_puanlari')->count());
    }

    public function test_ip_basina_cevirme_siniri(): void
    {
        config(['online.cark_misafir_ip' => 2]);
        $this->dilimler('hizmet_indirimi', 20);

        $this->cevir();
        $this->cevir();
        $this->postJson('/api/v2/vitrin/'.self::SALON.'/cark/cevir')->assertStatus(429);
    }

    public function test_odulsuz_dilimde_taslak_uretilmez(): void
    {
        $this->dilimler('tekrar_dene', 0);
        DB::table('carkifelek_dilimleri')->where('id', 12)->update(['deger' => null]);

        $cevir = $this->cevir();

        $this->assertFalse($cevir['kayitGerekli']);
        $this->assertNull($cevir['taslak']);
        $this->assertNull($cevir['sonrakiHakTarihi'], 'tekrar dene hak harcamaz');
    }
}
