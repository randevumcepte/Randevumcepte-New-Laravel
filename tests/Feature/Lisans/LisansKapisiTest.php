<?php

namespace Tests\Feature\Lisans;

use App\Http\Middleware\LisansKapisi;
use App\Models\IsletmeYetkilileri;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\SubeGrubu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RotaNesnesi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lisans kapisi: suresi biten salon kapanir; sure uzatilinca AYNI kimlikle bir sonraki istek acilir
 * (onbellek yok). Muaf uclar, gun sonu ve saat dilimi. Izole sqlite; rotalar testte tanimlanir.
 */
class LisansKapisiTest extends TestCase
{
    private const YETKILI = 7;

    private const SALON = 20;

    private const SUBE = 21;

    private const BASKA_SALON = 99;

    private const YOL = '/api/v2/lisans-test/ic';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'lisans_sqlite',
            'database.connections.lisans_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'app.timezone' => 'Europe/Istanbul',
            'lisans.aktif' => true,
        ]);
        DB::purge('lisans_sqlite');
        DB::setDefaultConnection('lisans_sqlite');
        $this->semaKur();

        $tamam = fn (Request $r) => response()->json(['basarili' => true, 'veri' => $r->path(), 'mesaj' => null]);
        // Kayit id'li uc: salonu kayittan alir (burada yoldan) ve salon erisiminin tek gecidinden gecer.
        $kayitli = function (Request $r, int $kayitSalonu) use ($tamam) {
            app(GuvenlikKapisi::class)->kapsamDenetle((int) auth('isletmeyonetim-api')->id(), $kayitSalonu);

            return $tamam($r);
        };
        // Muaf uclar da kapsam gecidinden gecer (gercek kabuk/profil gibi); lisansa bakilmamali.
        $muaf = fn (Request $r) => $kayitli($r, self::SALON);
        Route::middleware(['api', 'auth:isletmeyonetim-api'])->group(function () use ($tamam, $kayitli, $muaf) {
            Route::match(['GET', 'POST'], 'api/v2/lisans-test/ic', $tamam);
            Route::get('api/v2/lisans-test/kayit/{id}', $tamam);
            // Coklu sube hedefi: middleware'in okumadigi alandan (hedef) SubeGrubu'nun kendi lisans karari denenir.
            Route::post('api/v2/lisans-test/sube-grubu', fn (Request $r) => response()->json(['basarili' => true,
                'veri' => app(SubeGrubu::class)->hedefler((int) auth('isletmeyonetim-api')->id(), self::SUBE, $r->input('hedef'), 'cark.yonet'), 'mesaj' => null]));
            Route::match(['GET', 'DELETE'], 'api/v2/lisans-test/kayit-salon/{kayitSalonu}', $kayitli);
            // Muaf uclar: gercek controller yerine ayni yolda test cevabi (kapi yalniz yola bakar).
            Route::get('api/v2/hesabim', $muaf);
            Route::put('api/v2/hesabim/fatura-bilgisi', $muaf);
            Route::get('api/v2/kabuk', $muaf);
            Route::get('api/v2/profil', $muaf);
            Route::get('api/v2/personel-yetki/benim', $muaf);
            Route::post('api/v2/bildirim/cihaz', $muaf);
        });
        Route::middleware(['api', 'auth:isletmeyonetim-api,api'])->group(function () use ($tamam) {
            Route::post('api/v2/oturum/yenile', $tamam);
            Route::post('api/v2/oturum/cikis', $tamam);
        });

        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Europe/Istanbul'));
    }

    private function semaKur(): void
    {
        Schema::create('isletmeyetkilileri', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            $t->string('salon_adi')->nullable();
            $t->date('uyelik_bitis_tarihi')->nullable();
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('yetkili_id')->nullable();
            $t->integer('salon_id')->nullable();
            $t->integer('role_id')->nullable();
            $t->integer('aktif')->default(1);
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->integer('role_id');
            $t->string('model_type')->nullable();
            $t->integer('model_id');
            $t->integer('salon_id')->nullable();
        });

        DB::table('isletmeyetkilileri')->insert(['id' => self::YETKILI, 'name' => 'Yetkili']);
        DB::table('salonlar')->insert([
            ['id' => self::SALON, 'salon_adi' => 'Merkez', 'uyelik_bitis_tarihi' => '2026-11-07'],
            ['id' => self::BASKA_SALON, 'salon_adi' => 'Baska', 'uyelik_bitis_tarihi' => '2020-01-01'],
        ]);
        DB::table('salon_personelleri')->insert(['yetkili_id' => self::YETKILI, 'salon_id' => self::SALON]);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'x', 'model_id' => self::YETKILI, 'salon_id' => self::SALON]);
    }

    private function giris(): self
    {
        return $this->actingAs(IsletmeYetkilileri::find(self::YETKILI), 'isletmeyonetim-api');
    }

    private function bitis(int $salon, ?string $tarih): void
    {
        DB::table('salonlar')->where('id', $salon)->update(['uyelik_bitis_tarihi' => $tarih]);
    }

    public function test_suresi_biten_salon_402_ve_makine_okunur_kod_doner(): void
    {
        $this->giris()->getJson(self::YOL.'?salon_id='.self::SALON)
            ->assertStatus(402)
            ->assertJsonPath('basarili', false)
            ->assertJsonPath('veri.kod', 'lisans_suresi_doldu')
            ->assertJsonPath('veri.salon_id', self::SALON)
            ->assertJsonPath('veri.uyelik_bitis_tarihi', '2026-11-07')
            ->assertJsonPath('veri.aktif_olmadi', false)
            ->assertJsonPath('mesaj', fn ($m) => str_contains($m, '07.11.2026'));
    }

    public function test_sure_uzatilinca_ayni_kimlikle_sonraki_istek_acilir(): void
    {
        $this->giris()->getJson(self::YOL.'?salon_id='.self::SALON)->assertStatus(402);

        $this->bitis(self::SALON, '2027-11-08');

        $this->getJson(self::YOL.'?salon_id='.self::SALON)->assertOk();
        $this->postJson(self::YOL, ['sube' => self::SALON])->assertOk();
    }

    public function test_sure_kisaltilinca_ayni_kimlikle_sonraki_istek_kapanir(): void
    {
        $this->bitis(self::SALON, '2027-11-08');
        $this->giris()->getJson(self::YOL.'?salon_id='.self::SALON)->assertOk();

        $this->bitis(self::SALON, '2026-11-07');

        $this->getJson(self::YOL.'?salon_id='.self::SALON)->assertStatus(402);
    }

    public function test_bitis_gunu_sonuna_kadar_acik_ertesi_gun_kapali(): void
    {
        $this->bitis(self::SALON, '2026-11-08');

        $this->travelTo(Carbon::parse('2026-11-08 23:59:59', 'Europe/Istanbul'));
        $this->giris()->getJson(self::YOL.'?salon_id='.self::SALON)->assertOk();

        $this->travelTo(Carbon::parse('2026-11-09 00:00:00', 'Europe/Istanbul'));
        $this->getJson(self::YOL.'?salon_id='.self::SALON)->assertStatus(402);
    }

    public function test_utc_saatte_istanbul_gun_siniri_kullanilir(): void
    {
        $this->bitis(self::SALON, '2026-11-08');

        // 20:59:59 UTC = 23:59:59 Istanbul → acik; 21:00:00 UTC = 00:00 Istanbul → kapali.
        $this->travelTo(Carbon::parse('2026-11-08 20:59:59', 'UTC'));
        $this->giris()->getJson(self::YOL.'?salon_id='.self::SALON)->assertOk();

        $this->travelTo(Carbon::parse('2026-11-08 21:00:00', 'UTC'));
        $this->getJson(self::YOL.'?salon_id='.self::SALON)->assertStatus(402);
    }

    public function test_bos_bitis_tarihi_lisans_aktif_olmadi_olarak_kapali(): void
    {
        $this->bitis(self::SALON, null);

        $this->giris()->getJson(self::YOL.'?salon_id='.self::SALON)
            ->assertStatus(402)
            ->assertJsonPath('veri.uyelik_bitis_tarihi', null)
            ->assertJsonPath('veri.aktif_olmadi', true);
    }

    public function test_muaf_uclar_lisans_bitse_de_acik(): void
    {
        $this->giris();
        $q = '?salon_id='.self::SALON;

        $this->getJson('/api/v2/hesabim'.$q)->assertOk();
        $this->putJson('/api/v2/hesabim/fatura-bilgisi'.$q)->assertOk();
        $this->getJson('/api/v2/kabuk'.$q)->assertOk();
        $this->getJson('/api/v2/profil'.$q)->assertOk();
        $this->getJson('/api/v2/personel-yetki/benim'.$q)->assertOk();
        $this->postJson('/api/v2/bildirim/cihaz'.$q)->assertOk();
        $this->postJson('/api/v2/oturum/yenile'.$q)->assertOk();
        $this->postJson('/api/v2/oturum/cikis'.$q)->assertOk();
    }

    public function test_bagli_olmayan_salonun_lisansi_denetlenmez_ve_sizmaz(): void
    {
        // Bagli salon acik, istenen salon baskasinin (suresi dolmus): kapi gecer, kapsam reddi controller'in isidir.
        $this->bitis(self::SALON, '2027-11-08');

        $this->giris()->getJson(self::YOL.'?salon_id='.self::BASKA_SALON)->assertOk();
    }

    public function test_salonsuz_kayit_ucu_tek_salonda_kapali_salon_bildirilmez(): void
    {
        // Salonsuz istekte salon_id null: Flutter null'da acik paneli kapatir.
        $this->giris()->getJson('/api/v2/lisans-test/kayit/5')
            ->assertStatus(402)
            ->assertJsonPath('veri.kod', 'lisans_suresi_doldu')
            ->assertJsonPath('veri.salon_id', null)
            ->assertJsonPath('veri.uyelik_bitis_tarihi', '2026-11-07');
    }

    public function test_bagli_olmayan_salon_id_ile_lisans_atlatilamaz(): void
    {
        // Tahsilat silme: salon kayittan alinir; istekte uydurma salon_id. Tek salon kapali → 402, salon soylenmez.
        $this->giris()->deleteJson('/api/v2/lisans-test/kayit-salon/'.self::SALON.'?salon_id=999999')
            ->assertStatus(402)
            ->assertJsonPath('veri.salon_id', null);
        $this->deleteJson('/api/v2/lisans-test/kayit-salon/'.self::SALON, ['salon_id' => self::BASKA_SALON])
            ->assertStatus(402)
            ->assertJsonPath('veri.salon_id', null);
    }

    public function test_kayit_idli_uc_baska_salon_id_ile_kapsam_gecidinde_kapanir(): void
    {
        // Acik sube varken istek acik subeyi (ya da baskasini) gosterse de kaydin salonu kapali → 402.
        DB::table('salonlar')->insert(['id' => self::SUBE, 'salon_adi' => 'Sube', 'uyelik_bitis_tarihi' => '2027-01-01']);
        DB::table('salon_personelleri')->insert(['yetkili_id' => self::YETKILI, 'salon_id' => self::SUBE]);
        $yol = '/api/v2/lisans-test/kayit-salon/'.self::SALON;

        $this->giris()->getJson($yol.'?salon_id='.self::SUBE)
            ->assertStatus(402)
            ->assertJsonPath('veri.salon_id', self::SALON);
        $this->getJson($yol.'?salon_id=999999')->assertStatus(402);
        $this->deleteJson($yol, ['salon_id' => self::SUBE])->assertStatus(402);
        $this->getJson('/api/v2/lisans-test/kayit-salon/'.self::SUBE.'?salon_id='.self::SUBE)->assertOk();

        // Sure uzatilinca ayni kimlikle acilir (onbellek yok).
        $this->bitis(self::SALON, '2027-11-08');
        $this->getJson($yol.'?salon_id='.self::SUBE)->assertOk();
        $this->deleteJson($yol.'?salon_id=999999')->assertOk();
    }

    public function test_farkli_salon_alanlarinin_hepsi_denetlenir(): void
    {
        DB::table('salonlar')->insert(['id' => self::SUBE, 'salon_adi' => 'Sube', 'uyelik_bitis_tarihi' => '2027-01-01']);
        DB::table('salon_personelleri')->insert(['yetkili_id' => self::YETKILI, 'salon_id' => self::SUBE]);

        $this->giris()->postJson(self::YOL, ['salon_id' => self::SUBE, 'sube' => self::SALON])
            ->assertStatus(402)
            ->assertJsonPath('veri.salon_id', self::SALON);
        $this->postJson(self::YOL, ['salon_id' => self::SUBE, 'salonid' => self::SUBE])->assertOk();
    }

    public function test_salon_ids_dizisi_de_denetlenir(): void
    {
        DB::table('salonlar')->insert(['id' => self::SUBE, 'salon_adi' => 'Sube', 'uyelik_bitis_tarihi' => '2027-01-01']);
        DB::table('salon_personelleri')->insert(['yetkili_id' => self::YETKILI, 'salon_id' => self::SUBE]);

        $this->giris()->postJson(self::YOL, ['salon_id' => self::SUBE, 'salon_ids' => [self::SUBE, self::SALON]])
            ->assertStatus(402)->assertJsonPath('veri.salon_id', self::SALON);
        $this->postJson(self::YOL, ['salon_id' => self::SUBE, 'salon_ids' => json_encode([self::SUBE])])->assertOk();
        $this->postJson(self::YOL, ['salon_id' => self::SUBE, 'salon_ids' => [['x']]])->assertOk();
    }

    public function test_sube_grubu_kapali_subeye_yazmaz_402(): void
    {
        DB::table('salonlar')->insert(['id' => self::SUBE, 'salon_adi' => 'Sube', 'uyelik_bitis_tarihi' => '2027-01-01']);
        DB::table('salon_personelleri')->insert(['yetkili_id' => self::YETKILI, 'salon_id' => self::SUBE, 'role_id' => 1]);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'x', 'model_id' => self::YETKILI, 'salon_id' => self::SUBE]);
        DB::table('salon_personelleri')->where('salon_id', self::SALON)->update(['role_id' => 1]);

        $this->giris()->postJson('/api/v2/lisans-test/sube-grubu?salon_id='.self::SUBE, ['hedef' => [self::SUBE, self::SALON]])
            ->assertStatus(402)->assertJsonPath('veri.salon_id', self::SALON);
        $this->postJson('/api/v2/lisans-test/sube-grubu?salon_id='.self::SUBE, ['hedef' => [self::SUBE]])
            ->assertOk()->assertJsonPath('veri', [self::SUBE]);

        $this->bitis(self::SALON, '2027-01-01');
        $this->postJson('/api/v2/lisans-test/sube-grubu?salon_id='.self::SUBE, ['hedef' => '21,20'])
            ->assertOk()->assertJsonPath('veri', [self::SUBE, self::SALON]);
    }

    public function test_cok_subede_salonsuz_uc_acik_sube_varken_gecer_salonlu_uc_kapali_subede_kapanir(): void
    {
        DB::table('salonlar')->insert(['id' => self::SUBE, 'salon_adi' => 'Sube', 'uyelik_bitis_tarihi' => '2027-01-01']);
        DB::table('salon_personelleri')->insert(['yetkili_id' => self::YETKILI, 'salon_id' => self::SUBE]);

        $this->giris()->getJson('/api/v2/lisans-test/kayit/5')->assertOk();
        $this->getJson(self::YOL.'?salon_id='.self::SUBE)->assertOk();
        $this->getJson(self::YOL.'?salon_id='.self::SALON)->assertStatus(402);
    }

    public function test_kimliksiz_istek_401_kalir(): void
    {
        $this->getJson(self::YOL.'?salon_id='.self::SALON)->assertUnauthorized();
    }

    public function test_kapi_kapatilinca_denetlemez(): void
    {
        config(['lisans.aktif' => false]);

        $this->giris()->getJson(self::YOL.'?salon_id='.self::SALON)->assertOk();
    }

    public function test_salon_hesabina_gir_tokeni_kapidan_gecer(): void
    {
        $this->giris();
        $kapi = app(LisansKapisi::class);
        $istek = function (?string $tokenId) {
            $r = Request::create(self::YOL, 'GET', ['salon_id' => self::SALON]);
            $r->setRouteResolver(fn () => (new RotaNesnesi(['GET'], 'api/v2/lisans-test/ic', fn () => null))
                ->middleware(['api', 'auth:isletmeyonetim-api']));
            $r->attributes->set('oauth_access_token_id', $tokenId);

            return $r;
        };
        $gecti = fn () => response('gecti');

        $imp = $istek('imp_abc');
        $this->assertSame('gecti', $kapi->handle($imp, $gecti)->getContent());
        $this->assertFalse(LisansKapisi::denetlenir($imp), 'salon hesabina giriste kapsam gecidi lisansa bakmaz');
        $normal = $istek('normal');
        $this->assertSame(402, $kapi->handle($normal, $gecti)->getStatusCode());
        $this->assertTrue(LisansKapisi::denetlenir($normal));
        $this->assertFalse(LisansKapisi::denetlenir(Request::create('/')), 'isaretsiz istek (kuyruk, sistem, musteri) denetlenmez');
    }

    public function test_kapi_api_grubuna_kayitli(): void
    {
        $this->assertContains(LisansKapisi::class, app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['api'] ?? []);
    }

    public function test_yardimci_kurallar(): void
    {
        $this->assertTrue(LisansKapisi::isletmeRotasi(['api', 'auth:isletmeyonetim-api']));
        $this->assertTrue(LisansKapisi::isletmeRotasi(['api', 'auth:isletmeyonetim-api,api']));
        $this->assertFalse(LisansKapisi::isletmeRotasi(['api', 'auth:api']));
        $this->assertFalse(LisansKapisi::isletmeRotasi(['api', 'auth:sistemyonetim-api']));

        $desen = config('lisans.muaf_yollar');
        $this->assertTrue(LisansKapisi::muafMi('hesabim', $desen));
        $this->assertTrue(LisansKapisi::muafMi('oturum/yenile', $desen));
        $this->assertFalse(LisansKapisi::muafMi('randevu/takvim', $desen));
        $this->assertFalse(LisansKapisi::muafMi('hesabimx', $desen));
        $this->assertFalse(LisansKapisi::muafMi('bildirim/sayac', $desen));

        $this->assertSame([20], LisansKapisi::istenenSalonlar(['salon_id' => '20']));
        $this->assertSame([21], LisansKapisi::istenenSalonlar(['sube' => 21]));
        $this->assertSame([22], LisansKapisi::istenenSalonlar(['salonid' => '22']));
        $this->assertSame([20, 21], LisansKapisi::istenenSalonlar(['salon_id' => '20', 'sube' => 21, 'salonid' => '20']));
        $this->assertSame([], LisansKapisi::istenenSalonlar(['salon_id' => ['x']]));
        $this->assertSame([], LisansKapisi::istenenSalonlar([]));
        $this->assertSame([20, 21, 22], LisansKapisi::istenenSalonlar(['salon_id' => '20', 'salon_ids' => [21, '22', 20]]));
        $this->assertSame([23, 24], LisansKapisi::istenenSalonlar(['salon_ids' => '[23,24]']));
        $this->assertSame([25], LisansKapisi::istenenSalonlar(['salon_ids' => '25,x']));
        $this->assertSame([5], LisansKapisi::istenenSalonlar(['salon_ids' => [['x'], 5]]), 'ic ice dizi yok sayilir');
    }
}
