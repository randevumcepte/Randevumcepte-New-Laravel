<?php

namespace Tests\Feature\Oturum;

use App\Domain\Telefon\TelefonNumarasi;
use App\Services\Deneme\DemoHesapKurServisi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Services\Oturum\IlkSayfa;
use App\Services\Oturum\PlatformSms;
use App\Services\Oturum\SalonMesaj;
use App\Services\Whatsapp\Kopru\KopruCevabi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Giris, sifre sifirlama ve deneme uclari — izole sqlite, paylasilan veritabanina dokunmaz. */
class OturumApiTest extends TestCase
{
    private const TEL = '5321112233';

    private const ANA_BUNDLE = 'com.randevumcepte.randevumcepte';

    private array $smsler = [];

    /** @var array<int,array{tel:string,mesaj:string}> */
    private array $whatsapplar = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'oturum_sqlite',
            'database.connections.oturum_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('oturum_sqlite');
        DB::setDefaultConnection('oturum_sqlite');
        $this->semaKur();

        $token = Mockery::mock(ErisimTokenServisi::class)->makePartial();
        $token->shouldReceive('uret')->andReturn('test-token');
        $this->app->instance(ErisimTokenServisi::class, $token);

        $ilkSayfa = Mockery::mock(IlkSayfa::class);
        $ilkSayfa->shouldReceive('coz')->andReturn('/v2/ozet?sube=1');
        $this->app->instance(IlkSayfa::class, $ilkSayfa);

        $sms = Mockery::mock(PlatformSms::class);
        $sms->shouldReceive('gonder')->andReturnUsing(function ($tel, $mesaj) {
            $this->smsler[] = compact('tel', 'mesaj');

            return true;
        });
        $this->app->instance(PlatformSms::class, $sms);

        $this->whatsappKur(false);
    }

    private function whatsappKur(bool $basarili): void
    {
        $kopru = Mockery::mock(WhatsappKoprusu::class);
        $kopru->shouldReceive('gonder')->andReturnUsing(function ($oturum, $tel, $mesaj) use ($basarili) {
            $this->whatsapplar[] = compact('tel', 'mesaj');

            return new KopruCevabi($basarili ? 200 : 0);
        });
        $this->app->instance(WhatsappKoprusu::class, $kopru);
    }

    private function altiHane(array $mesajlar): string
    {
        preg_match('/\b(\d{6})\b/', (string) end($mesajlar)['mesaj'], $m);

        return $m[1];
    }

    private function semaKur(): void
    {
        Schema::create('isletmeyetkilileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'gsm1', 'gsm2', 'password', 'dogrulama_kodu', 'profil_resim'] as $k) {
                $t->string($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'cep_telefon', 'password', 'meslek', 'cinsiyet', 'ev_telefon', 'dogum_tarihi', 'profil_resim', 'adres'] as $k) {
                $t->string($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            foreach (['salon_adi', 'app_bundle', 'domain', 'telefon_1', 'sms_secret'] as $k) {
                $t->string($k)->nullable();
            }
            foreach (['uyelik_turu', 'musteri_online_randevu_aktif', 'studyo_modu', 'grup_dersi_aktif'] as $k) {
                $t->integer($k)->default(1);
            }
            $t->date('uyelik_bitis_tarihi')->nullable();
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('yetkili_id');
            $t->integer('role_id')->nullable();
            $t->integer('aktif')->default(1);
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
        Schema::create('bildirim_kimlikleri', function (Blueprint $t) {
            $t->id();
            $t->integer('isletme_yetkili_id')->nullable();
            $t->integer('user_id')->nullable();
            foreach (['bildirim_id', 'cihaz', 'app_bundle'] as $k) {
                $t->string($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('oauth_access_tokens', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->integer('user_id');
            $t->integer('revoked')->default(0);
            $t->timestamps();
        });
        Schema::create('v2_bildirim_kuyrugu', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->string('tur');
            $t->integer('randevu_id')->nullable();
            $t->integer('yetkili_id')->nullable();
            $t->text('payload')->nullable();
            $t->integer('durum')->default(0);
            $t->integer('deneme')->default(0);
            $t->timestamps();
        });
        Schema::create('salon_turu', function (Blueprint $t) {
            $t->id();
            $t->string('salon_turu_adi');
        });
    }

    private function yetkili(array $salonlar, array $alan = []): int
    {
        $id = DB::table('isletmeyetkilileri')->insertGetId($alan + ['name' => 'Yetkili', 'gsm1' => self::TEL, 'email' => 'y@ornek.com', 'password' => Hash::make('gizli123'), 'dogrulama_kodu' => '9999']);
        foreach ($salonlar as $salon) {
            $salonId = DB::table('salonlar')->insertGetId($salon + ['salon_adi' => 'Salon', 'sms_secret' => 'GIZLI']);
            DB::table('salon_personelleri')->insert(['salon_id' => $salonId, 'yetkili_id' => $id, 'role_id' => 1]);
        }

        return $id;
    }

    private function giris(array $g)
    {
        return $this->postJson('/api/v2/oturum/giris', $g);
    }

    private function sonKod(): string
    {
        preg_match('/\b(\d{4})\b/', end($this->smsler)['mesaj'], $m);

        return $m[1];
    }

    public function test_web_girisi_telefon_ve_eposta_ile_olur_gizli_alan_cikmaz(): void
    {
        $this->yetkili([['uyelik_turu' => 3]]);

        foreach (['0532 111 22 33', 'y@ornek.com'] as $kimlik) {
            $r = $this->giris(['kimlik' => $kimlik, 'password' => 'gizli123', 'hesap_turu' => 'isletme'])->assertOk();
            $r->assertJsonPath('veri.token', 'test-token')->assertJsonPath('veri.user_type', '1')->assertJsonPath('veri.ilk_sayfa', '/v2/ozet?sube=1');
        }
        $govde = $r->getContent();
        $this->assertStringNotContainsString('GIZLI', $govde);
        $this->assertStringNotContainsString('password', $govde);
        $this->assertStringNotContainsString('dogrulama_kodu', $govde);
        // Softphone kimligi ve personel listesi web girisine gitmez.
        $this->assertStringNotContainsString('dahili', $govde);
        $this->assertStringNotContainsString('personeller', $govde);
    }

    public function test_yanlis_sifre_401_ve_cok_denemede_429(): void
    {
        $this->yetkili([['uyelik_turu' => 1]]);
        for ($i = 0; $i < 10; $i++) {
            $this->giris(['kimlik' => self::TEL, 'password' => 'yanlis'])->assertStatus(401);
        }
        $this->giris(['kimlik' => self::TEL, 'password' => 'gizli123'])->assertStatus(429);
    }

    public function test_ana_uygulama_yalniz_uyelik_3_disi_salonlari_gorur(): void
    {
        $this->yetkili([['uyelik_turu' => 3]]);
        $this->giris(['kimlik' => self::TEL, 'password' => 'gizli123', 'app_bundle' => self::ANA_BUNDLE])->assertStatus(401);

        DB::table('salon_personelleri')->insert(['salon_id' => DB::table('salonlar')->insertGetId(['uyelik_turu' => 1, 'salon_adi' => 'Acik']), 'yetkili_id' => 1]);
        $this->giris(['kimlik' => self::TEL, 'password' => 'gizli123', 'app_bundle' => self::ANA_BUNDLE])->assertOk()
            ->assertJsonCount(1, 'veri.user.yetkili_olunan_isletmeler')
            ->assertJsonPath('veri.user.yetkili_olunan_isletmeler.0.salonlar.salon_adi', 'Acik')
            ->assertJsonPath('veri.ilk_sayfa', null);
    }

    public function test_marka_uygulamasi_markanin_salonlarini_gorur_ve_cihaz_bir_kez_kaydedilir(): void
    {
        $this->yetkili([['app_bundle' => 'com.marka'], ['app_bundle' => 'com.baska']]);
        $g = ['kimlik' => self::TEL, 'password' => 'gizli123', 'app_bundle' => 'com.marka', 'bildirim_id' => 'cihaz-1'];

        $this->giris($g)->assertOk()->assertJsonCount(1, 'veri.user.yetkili_olunan_isletmeler');
        $this->giris($g)->assertOk();

        $this->assertSame(1, DB::table('bildirim_kimlikleri')->where('bildirim_id', 'cihaz-1')->count());
    }

    public function test_flutter_null_bildirim_kimligi_kaydedilmez(): void
    {
        $this->yetkili([['uyelik_turu' => 1]]);
        $this->giris(['kimlik' => self::TEL, 'password' => 'gizli123', 'app_bundle' => self::ANA_BUNDLE, 'bildirim_id' => 'null'])->assertOk();

        $this->assertSame(0, DB::table('bildirim_kimlikleri')->count());
    }

    public function test_musteri_kendi_turuyle_girer_panel_girisinden_giremez(): void
    {
        DB::table('users')->insert(['name' => 'Müşteri', 'cep_telefon' => self::TEL, 'password' => Hash::make('gizli123')]);

        $this->giris(['kimlik' => self::TEL, 'password' => 'gizli123', 'hesap_turu' => 'isletme'])->assertStatus(401);
        $this->giris(['kimlik' => self::TEL, 'password' => 'gizli123', 'app_bundle' => self::ANA_BUNDLE])->assertOk()
            ->assertJsonPath('veri.user_type', '0')->assertJsonPath('veri.user', '')->assertJsonPath('veri.musteri.name', 'Müşteri');
    }

    public function test_sifre_sifirlama_kodla_olur_kod_baska_hesabi_acmaz(): void
    {
        $id = $this->yetkili([['uyelik_turu' => 1]]);
        DB::table('oauth_access_tokens')->insert(['id' => 'eski', 'user_id' => $id]);
        $istek = ['cep_telefon' => self::TEL, 'hesap_turu' => 'isletme'];

        $this->postJson('/api/v2/oturum/sifre-kodu', $istek)->assertOk();
        $kod = $this->sonKod();

        $this->postJson('/api/v2/oturum/sifre-sifirla', $istek + ['kod' => $kod, 'yeni_sifre' => 'yeni12345'])->assertOk();
        $this->assertTrue(Hash::check('yeni12345', DB::table('isletmeyetkilileri')->where('id', $id)->value('password')));
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('id', 'eski')->value('revoked'));
        $this->assertSame('sifre_degisti', DB::table('v2_bildirim_kuyrugu')->value('tur'));

        // Kod tek kullanimlik.
        $this->postJson('/api/v2/oturum/sifre-sifirla', $istek + ['kod' => $kod, 'yeni_sifre' => 'baska12345'])->assertStatus(422);
    }

    public function test_kayitsiz_numara_ayni_cevabi_alir_ve_sms_gitmez(): void
    {
        $this->postJson('/api/v2/oturum/sifre-kodu', ['cep_telefon' => self::TEL])->assertOk();

        $this->assertSame([], $this->smsler);
    }

    public function test_inceleme_hesabinin_sifresi_sifirlanamaz(): void
    {
        $this->yetkili([['uyelik_turu' => 1]]);
        config(['oturum.korunan_hesaplar.isletme' => [1]]);

        $this->postJson('/api/v2/oturum/sifre-kodu', ['cep_telefon' => self::TEL])->assertOk();

        $this->assertSame([], $this->smsler);
    }

    public function test_deneme_kodla_kurulur_yanlis_kod_ve_uye_numara_reddedilir(): void
    {
        $turId = DB::table('salon_turu')->insertGetId(['salon_turu_adi' => 'Kuaförler']);
        $kurulum = Mockery::mock(DemoHesapKurServisi::class);
        $kurulum->shouldReceive('kur')->once()->withArgs(fn ($g) => $g['cep_telefon'] === self::TEL && $g['isletme_adi'] === 'Salonum')
            ->andReturn(['yetkili_id' => 9, 'salon_id' => 7, 'sifre' => 'Sifre123']);
        $this->app->instance(DemoHesapKurServisi::class, $kurulum);
        $form = ['salon_turu_id' => $turId, 'isletme_adi' => 'Salonum', 'isletme_adresi' => 'Adres', 'ad_soyad' => 'Ad Soyad', 'cep_telefon' => '0532 111 22 33', 'email' => 'a@ornek.com'];

        $this->postJson('/api/v2/deneme/kod-gonder', ['isletme_adi' => 'Eksik'])->assertStatus(422);
        $this->postJson('/api/v2/deneme/kod-gonder', $form)->assertOk();
        $kod = $this->sonKod();

        $this->postJson('/api/v2/deneme/dogrula', ['cep_telefon' => self::TEL, 'kod' => $kod === '0000' ? '1111' : '0000'])->assertStatus(422);
        $this->postJson('/api/v2/deneme/dogrula', ['cep_telefon' => self::TEL, 'kod' => $kod])->assertOk()
            ->assertJsonPath('veri.salon_id', 7)->assertJsonPath('veri.giris_yolu', '/v2/giris');
        $this->assertStringContainsString('Sifre123', end($this->smsler)['mesaj']);

        $this->yetkili([]);
        $this->postJson('/api/v2/deneme/kod-gonder', $form)->assertStatus(409);
    }

    public function test_deneme_kapaliyken_409(): void
    {
        config(['oturum.deneme.yazma_aktif' => false]);

        $this->postJson('/api/v2/deneme/kod-gonder', [])->assertStatus(409);
    }

    public function test_kod_once_whatsapp_tan_gider_yeniden_isteyince_sms(): void
    {
        $this->whatsappKur(true);
        $this->yetkili([['uyelik_turu' => 1]]);

        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();
        $this->assertCount(1, $this->whatsapplar);
        $this->assertSame([], $this->smsler);

        // Yeniden gonderme bekleme suresi icinde yeni mesaj gitmez.
        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();
        $this->assertCount(1, $this->whatsapplar);

        RateLimiter::clear('oturum-kod-tel:'.TelefonNumarasi::kanonik(self::TEL));
        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();
        $this->assertCount(1, $this->whatsapplar);
        $this->assertCount(1, $this->smsler);
    }

    public function test_whatsapp_basarisizsa_kod_sms_ile_gider_ve_giris_olur(): void
    {
        $this->yetkili([['uyelik_turu' => 1]]);

        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();
        $kod = $this->altiHane($this->smsler);

        $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => $kod])
            ->assertOk()->assertJsonPath('veri.token', 'test-token')->assertJsonPath('veri.user_type', '1');

        // Kod tek kullanimlik.
        $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => $kod])->assertStatus(422);
    }

    public function test_kayitsiz_numaraya_kod_gitmez_cevap_ayni(): void
    {
        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk()->assertJsonPath('veri.yeniden_sn', 60);

        $this->assertSame([], $this->smsler);
        $this->assertSame([], $this->whatsapplar);
    }

    public function test_bes_yanlis_denemede_kod_duser(): void
    {
        $this->yetkili([['uyelik_turu' => 1]]);
        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();
        $kod = $this->altiHane($this->smsler);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => $kod === '000000' ? '000001' : '000000'])->assertStatus(422);
        }

        $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => $kod])->assertStatus(422);
    }

    public function test_iki_hesapli_numara_tur_secer(): void
    {
        $this->yetkili([['uyelik_turu' => 1]]);
        DB::table('users')->insert(['name' => 'Musteri', 'cep_telefon' => self::TEL, 'password' => Hash::make('gizli123')]);

        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();
        $r = $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => $this->altiHane($this->smsler)])
            ->assertOk()->assertJsonPath('veri.hesap_secimi', ['isletme', 'musteri'])->assertJsonMissingPath('veri.token');

        $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => $r->json('veri.secim_kodu'), 'hesap_turu' => 'isletme'])
            ->assertOk()->assertJsonPath('veri.user_type', '1');
    }

    public function test_inceleme_hesabina_mesaj_gitmez_sabit_kodla_girer(): void
    {
        $id = $this->yetkili([['uyelik_turu' => 1]]);
        config(['oturum.korunan_hesaplar.isletme' => [$id], 'oturum.tek_kullanimlik.inceleme_kodu' => '123456']);

        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();
        $this->assertSame([], $this->smsler);

        $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => '654321'])->assertStatus(422);
        $this->postJson('/api/v2/oturum/kod-dogrula', ['cep_telefon' => self::TEL, 'kod' => '123456'])->assertOk();
    }

    public function test_sifre_kodu_yeniden_isteyince_sms_ile_gider(): void
    {
        $this->whatsappKur(true);
        $this->yetkili([['uyelik_turu' => 1]]);
        $istek = ['cep_telefon' => self::TEL, 'hesap_turu' => 'isletme'];

        $this->postJson('/api/v2/oturum/sifre-kodu', $istek)->assertOk();
        $this->assertCount(1, $this->whatsapplar);
        $this->assertSame([], $this->smsler);

        RateLimiter::clear('oturum-sifre-tel:'.TelefonNumarasi::kanonik(self::TEL));
        $this->postJson('/api/v2/oturum/sifre-kodu', $istek)->assertOk();
        $this->assertCount(1, $this->whatsapplar);
        $this->assertCount(1, $this->smsler);
    }

    public function test_mobil_giris_kendi_dahili_hattini_alir(): void
    {
        $this->yetkili([['uyelik_turu' => 1]]);

        $r = $this->giris(['kimlik' => self::TEL, 'password' => 'gizli123', 'app_bundle' => self::ANA_BUNDLE])->assertOk();

        $satir = $r->json('veri.user.yetkili_olunan_isletmeler.0');
        $this->assertArrayHasKey('dahili_no_webrtc', $satir);
        $this->assertArrayHasKey('personeller', $satir['salonlar']);
    }

    public function test_web_giris_ekrani_kod_ve_sifre_formlarini_icerir(): void
    {
        $this->get('/v2/giris')->assertOk()->assertSee('id="kodIsteFormu"', false)->assertSee('id="kodGirisFormu"', false)->assertSee('id="girisFormu"', false);
    }

    /** @param  array<int,string>  $kanallar  cagrilan kanallar buraya yazilir */
    private function salonHattiKur(bool $whatsapp, bool $sms, array &$kanallar): void
    {
        $salon = Mockery::mock(SalonMesaj::class);
        $salon->shouldReceive('whatsapp')->andReturnUsing(function ($id, $tel, $mesaj) use ($whatsapp, &$kanallar) {
            $kanallar[] = 'whatsapp:'.$id;

            return $whatsapp;
        });
        $salon->shouldReceive('sms')->andReturnUsing(function ($id, $tel, $mesaj) use ($sms, &$kanallar) {
            $kanallar[] = 'sms:'.$id;

            return $sms;
        });
        $this->app->instance(SalonMesaj::class, $salon);
    }

    public function test_whatsapp_bagli_degilse_kod_isletmenin_sms_inden_gider_platforma_dusmez(): void
    {
        $kanallar = [];
        $this->salonHattiKur(false, true, $kanallar);
        $this->yetkili([['uyelik_turu' => 1]]);
        $salonId = (int) DB::table('salon_personelleri')->value('salon_id');

        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();

        $this->assertSame(['whatsapp:'.$salonId, 'sms:'.$salonId], $kanallar);
        $this->assertSame([], $this->smsler);
        $this->assertSame([], $this->whatsapplar);
    }

    public function test_isletme_whatsapp_i_bagliysa_sms_gitmez(): void
    {
        $kanallar = [];
        $this->salonHattiKur(true, true, $kanallar);
        $this->yetkili([['uyelik_turu' => 1]]);

        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();

        $this->assertCount(1, $kanallar);
        $this->assertStringStartsWith('whatsapp:', $kanallar[0]);
    }

    public function test_isletme_hatti_yoksa_kod_platformdan_gider(): void
    {
        $kanallar = [];
        $this->salonHattiKur(false, false, $kanallar);
        $this->yetkili([['uyelik_turu' => 1]]);

        $this->postJson('/api/v2/oturum/kod-iste', ['cep_telefon' => self::TEL])->assertOk();

        $this->assertCount(1, $this->smsler);
    }
}
