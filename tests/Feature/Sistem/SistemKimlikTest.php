<?php

namespace Tests\Feature\Sistem;

use App\Domain\Oturum\TokenTipi;
use App\Services\Oturum\ErisimTokenServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sistem yonetimi kimligi: gercek JWT + gercek guard'lar, izole sqlite. Asil kontrol: ayni sayisal id'li yonetici ve
 * yetkili birbirinin ucuna giremez. oauth anahtarlari yoksa (yerel kopya) atlanir.
 */
class SistemKimlikTest extends TestCase
{
    private const ID = 3;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file(storage_path('oauth-private.key'))) {
            $this->markTestSkipped('oauth anahtari yok');
        }
        config([
            'database.default' => 'sistem_sqlite',
            'database.connections.sistem_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sistem_sqlite');
        DB::setDefaultConnection('sistem_sqlite');
        $this->semaKur();

        DB::table('sistemyoneticileri')->insert(['id' => self::ID, 'name' => 'Yonetici', 'email' => 'y@test.tr', 'password' => Hash::make('gizli123'), 'rol' => 'destek', 'admin' => 0, 'aktif' => 1]);
        DB::table('isletmeyetkilileri')->insert(['id' => self::ID, 'name' => 'Baska Kisi', 'email' => 'b@test.tr', 'gsm1' => '5321112233', 'password' => Hash::make('x')]);

        Route::middleware(['api', 'auth:isletmeyonetim-api'])->get('/api/test/isletme', fn () => response()->json(['ad' => auth('isletmeyonetim-api')->user()->name]));
        Route::middleware(['api', 'auth:sistemyonetim-api', 'sistem.rol:super_admin'])->get('/api/test/super', fn () => response()->json(['tamam' => true]));
    }

    private function semaKur(): void
    {
        Schema::create('sistemyoneticileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'password', 'rol', 'telefon', 'son_giris_ip'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('admin')->default(0);
            $t->integer('aktif')->default(1);
            $t->timestamp('son_giris_tarihi')->nullable();
            $t->timestamps();
        });
        Schema::create('isletmeyetkilileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'gsm1', 'password'] as $k) {
                $t->string($k)->nullable();
            }
        });
        // Isletme guard'i tamamen pasif personeli her istekte reddeder (KapsamServisi::tamamenPasifMi).
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('yetkili_id')->nullable();
            $t->integer('salon_id');
            $t->integer('role_id')->nullable();
            $t->integer('aktif')->default(1);
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->integer('role_id');
            $t->string('model_type')->nullable();
            $t->integer('model_id');
            $t->integer('salon_id');
        });
        Schema::create('oauth_access_tokens', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->integer('user_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->string('name')->nullable();
            $t->text('scopes')->nullable();
            $t->integer('revoked')->default(0);
            $t->timestamps();
            $t->dateTime('expires_at')->nullable();
        });
        Schema::create('sistemyonetim_login_loglari', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            foreach (['email_attempt', 'hata', 'ip', 'user_agent'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('basarili')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('sistemyonetim_audit_log', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            foreach (['user_name', 'user_rol', 'action', 'target_type', 'target_label', 'ip', 'user_agent'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('target_id')->nullable();
            $t->text('aciklama')->nullable();
            $t->text('meta')->nullable();
            $t->timestamps();
        });
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function sistemTokeni(): string
    {
        return app(ErisimTokenServisi::class)->sistemYoneticisiUret(self::ID);
    }

    public function test_giris_token_doner_ve_loglanir(): void
    {
        $this->postJson('/api/v2/sistem/oturum/giris', ['email' => 'y@test.tr', 'password' => 'gizli123'])
            ->assertOk()->assertJsonPath('veri.kullanici.rol', 'destek');

        $this->assertSame(1, DB::table('sistemyonetim_login_loglari')->where('basarili', 1)->count());
    }

    public function test_yanlis_sifre_401_ve_loglanir(): void
    {
        $this->postJson('/api/v2/sistem/oturum/giris', ['email' => 'y@test.tr', 'password' => 'yanlis'])->assertStatus(401);

        $this->assertSame(1, DB::table('sistemyonetim_login_loglari')->where('basarili', 0)->count());
    }

    public function test_sistem_tokeni_isletme_ucuna_giremez(): void
    {
        $this->getJson('/api/test/isletme', $this->bearer($this->sistemTokeni()))->assertStatus(401);
    }

    public function test_isletme_tokeni_sistem_ucuna_giremez(): void
    {
        $token = app(ErisimTokenServisi::class)->uret(self::ID, TokenTipi::ISLETME);

        $this->getJson('/api/test/isletme', $this->bearer($token))->assertOk()->assertJsonPath('ad', 'Baska Kisi');
        $this->getJson('/api/v2/sistem/kabuk', $this->bearer($token))->assertStatus(401);
    }

    public function test_tamamen_pasif_personelin_tokeni_her_istekte_reddedilir(): void
    {
        $token = app(ErisimTokenServisi::class)->uret(self::ID, TokenTipi::ISLETME);
        DB::table('salon_personelleri')->insert(['yetkili_id' => self::ID, 'salon_id' => 20, 'role_id' => 5, 'aktif' => 1]);
        $this->getJson('/api/test/isletme', $this->bearer($token))->assertOk();

        // Pasife alma kuyrugu islenmeden (token iptal edilmeden) de oturum duser.
        DB::table('salon_personelleri')->where('yetkili_id', self::ID)->update(['aktif' => 0]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/test/isletme', $this->bearer($token))->assertStatus(401);
    }

    public function test_rol_kapisi_403(): void
    {
        $this->getJson('/api/test/super', $this->bearer($this->sistemTokeni()))->assertStatus(403);
    }

    public function test_pasif_edilen_yoneticinin_tokeni_duser(): void
    {
        $token = $this->sistemTokeni();
        DB::table('sistemyoneticileri')->where('id', self::ID)->update(['aktif' => 0]);

        $this->getJson('/api/test/super', $this->bearer($token))->assertStatus(401);
    }

    public function test_yetkili_sifre_iptali_yoneticiyi_dusurmez(): void
    {
        $this->sistemTokeni();
        $servis = app(ErisimTokenServisi::class);
        $servis->uret(self::ID, TokenTipi::ISLETME);

        $servis->digerleriniIptal(self::ID, null);

        $this->assertSame(0, DB::table('oauth_access_tokens')->where('name', 'sistemYonetim')->where('revoked', 1)->count());
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('name', 'appToken')->where('revoked', 1)->count());
    }

    public function test_impersonation_tokeni_taninir_ve_tek_tek_iptal_edilir(): void
    {
        $servis = app(ErisimTokenServisi::class);
        $servis->impersonationUret(self::ID, 77);
        $satir = DB::table('oauth_access_tokens')->where('name', 'impersonation:77')->first();

        $this->assertTrue(ErisimTokenServisi::impersonationTokeniMi($satir->id));
        $this->assertFalse(ErisimTokenServisi::impersonationTokeniMi('abc'));

        $servis->impersonationIptal(77);
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('id', $satir->id)->value('revoked'));
    }
}
