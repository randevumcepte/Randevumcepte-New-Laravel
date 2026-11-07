<?php

namespace Tests\Feature\Oturum;

use App\Domain\Oturum\TokenTipi;
use App\Services\Oturum\ErisimTokenServisi;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\TestCase;

/** Suresi dolmus token yalniz imzali musteri tipiyle yenilenir; izole sqlite + gecici anahtar. */
class MusteriTokenYenilemeTest extends TestCase
{
    private const USER = 7;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'yenileme_sqlite',
            'database.connections.yenileme_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('yenileme_sqlite');
        DB::setDefaultConnection('yenileme_sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id();
        });
        Schema::create('oauth_access_tokens', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->integer('user_id');
            $t->integer('client_id');
            $t->string('name')->nullable();
            $t->text('scopes')->nullable();
            $t->integer('revoked')->default(0);
            $t->timestamps();
            $t->dateTime('expires_at')->nullable();
        });
        DB::table('users')->insert(['id' => self::USER]);

        $dizin = sys_get_temp_dir().'/yenileme_'.uniqid();
        mkdir($dizin);
        $anahtar = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($anahtar, $gizli);
        file_put_contents($dizin.'/oauth-private.key', $gizli);
        file_put_contents($dizin.'/oauth-public.key', openssl_pkey_get_details($anahtar)['key']);
        $this->app->useStoragePath($dizin);
    }

    private function suresiniDoldur(): void
    {
        DB::table('oauth_access_tokens')->update(['expires_at' => now()->subDay()->toDateTimeString()]);
    }

    private function tipsizToken(): string
    {
        $simdi = new DateTimeImmutable();
        $ayar = Configuration::forAsymmetricSigner(new Sha256(), InMemory::file(storage_path('oauth-private.key')), InMemory::file(storage_path('oauth-public.key')));
        DB::table('oauth_access_tokens')->insert(['id' => 'tipsiz', 'user_id' => self::USER, 'client_id' => 1, 'name' => 'appToken', 'revoked' => 0, 'expires_at' => now()->subDay()->toDateTimeString()]);

        return $ayar->builder(ChainedFormatter::withUnixTimestampDates())->identifiedBy('tipsiz')->issuedAt($simdi)
            ->relatedTo((string) self::USER)->withClaim('scopes', [])
            ->getToken($ayar->signer(), $ayar->signingKey())->toString();
    }

    private function yenile(string $token)
    {
        return $this->withToken($token)->postJson('/api/v2/musteri-oturum/yenile');
    }

    public function test_imzali_musteri_tokeni_yenilenir_mobil_kapsam_korunur(): void
    {
        $eski = app(ErisimTokenServisi::class)->uret(self::USER, TokenTipi::MUSTERI, true);
        $this->suresiniDoldur();

        $yeni = $this->yenile($eski)->assertOk()->json('veri.token');

        $ayar = Configuration::forAsymmetricSigner(new Sha256(), InMemory::file(storage_path('oauth-private.key')), InMemory::file(storage_path('oauth-public.key')));
        $this->assertSame([TokenTipi::MUSTERI, TokenTipi::MOBIL], $ayar->parser()->parse($yeni)->claims()->get('scopes'));
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('revoked', 1)->count());
    }

    public function test_tipsiz_token_reddedilir(): void
    {
        $this->yenile($this->tipsizToken())->assertUnauthorized();
        $this->assertSame(1, DB::table('oauth_access_tokens')->count());
    }

    public function test_isletme_tokeni_reddedilir(): void
    {
        $token = app(ErisimTokenServisi::class)->uret(self::USER, TokenTipi::ISLETME, true);
        $this->suresiniDoldur();

        $this->yenile($token)->assertUnauthorized();
    }
}
