<?php

namespace Tests\Feature\Konsol;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * v2:test-hesaplari — izole sqlite; .env gecici bir klasore yonlendirilir (app()->useEnvironmentPath).
 * Salonun gercek sahibi, gercek personeli ve gercek musterisi her adimda degismeden kalmali.
 */
class TestHesaplariKomutuTest extends TestCase
{
    private const SALON = 20;

    private const DIGER_SALON = 30;

    private string $envKlasoru;

    private string $envYolu;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'test_hesap_sqlite',
            'database.connections.test_hesap_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('test_hesap_sqlite');
        DB::setDefaultConnection('test_hesap_sqlite');

        foreach ([
            'salonlar' => ['app_bundle', 'domain'],
            'isletmeyetkilileri' => ['gsm1', 'name', 'unvan', 'cinsiyet', 'password', 'profil_resim', 'aktif'],
            'salon_personelleri' => ['yetkili_id', 'salon_id', 'role_id', 'aktif', 'arsivli', 'takvimde_gorunsun', 'personel_adi', 'cep_telefon',
                'renk', 'unvan', 'cinsiyet', 'maas', 'hizmet_prim_yuzde', 'urun_prim_yuzde', 'paket_prim_yuzde', 'hizmet_prim_detayli', 'urun_prim_detayli', 'paket_prim_detayli'],
            'model_has_roles' => ['role_id', 'model_type', 'model_id', 'salon_id'],
            'roles' => ['name'],
            'personel_yetki_ayarlari' => ['personel_id', 'salon_id', 'sablon', 'ayarlar'],
            'v2_bildirim_kuyrugu' => ['salon_id', 'tur', 'randevu_id', 'yetkili_id', 'payload', 'durum', 'deneme'],
            'salon_aktivite_log' => ['salon_id', 'user_id', 'user_type', 'user_name', 'user_rol', 'action', 'target_type', 'target_id', 'target_label', 'aciklama', 'meta', 'ip', 'user_agent'],
            'users' => ['name', 'cep_telefon', 'password', 'email', 'tc_kimlik_no', 'cinsiyet'],
            'musteri_portfoy' => ['salon_id', 'user_id', 'tur', 'aktif', 'kara_liste', 'kvkk_onay_alindi', 'onay_kodu', 'musteri_tipi', 'ozel_notlar'],
            'randevular' => ['salon_id', 'user_id', 'durum', 'tarih', 'saat'],
            'randevu_hizmetler' => ['randevu_id', 'personel_id', 'hizmet_id', 'saat'],
        ] as $ad => $kolonlar) {
            Schema::create($ad, function (Blueprint $t) use ($kolonlar) {
                $t->id();
                foreach ($kolonlar as $k) {
                    $t->text($k)->nullable();
                }
                $t->timestamps();
            });
        }

        DB::table('salonlar')->insert([['id' => self::SALON], ['id' => self::DIGER_SALON]]);
        foreach ([1 => 'Hesap Sahibi', 2 => 'Müdür', 3 => 'Yönetici', 4 => 'Sekreter', 5 => 'Personel', 6 => 'Gizli'] as $id => $ad) {
            DB::table('roles')->insert(['id' => $id, 'name' => $ad]);
        }
        // Gercek sahip, gercek personel, gercek musteri — dokunulmamali.
        DB::table('isletmeyetkilileri')->insert([
            ['id' => 1, 'gsm1' => '5321112233', 'name' => 'Gercek Sahip', 'password' => 'sahip-hash'],
            ['id' => 2, 'gsm1' => '5327778899', 'name' => 'Ayse', 'password' => 'ayse-hash'],
        ]);
        DB::table('salon_personelleri')->insert([
            ['id' => 10, 'yetkili_id' => 1, 'salon_id' => self::SALON, 'role_id' => 1, 'aktif' => 1, 'arsivli' => 0, 'takvimde_gorunsun' => 1, 'personel_adi' => 'Gercek Sahip', 'cep_telefon' => '5321112233', 'renk' => 1],
            ['id' => 11, 'yetkili_id' => 2, 'salon_id' => self::SALON, 'role_id' => 5, 'aktif' => 1, 'arsivli' => 0, 'takvimde_gorunsun' => 1, 'personel_adi' => 'Ayse', 'cep_telefon' => '5327778899', 'renk' => 2],
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => 1, 'salon_id' => self::SALON],
            ['role_id' => 5, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => 2, 'salon_id' => self::SALON],
        ]);
        DB::table('users')->insert(['id' => 100, 'name' => 'Gercek Musteri', 'cep_telefon' => '5331234567', 'password' => 'musteri-hash']);
        DB::table('musteri_portfoy')->insert(['salon_id' => self::SALON, 'user_id' => 100, 'aktif' => 1]);

        $this->envKlasoru = sys_get_temp_dir().'/test-hesap-env-'.bin2hex(random_bytes(4));
        mkdir($this->envKlasoru);
        $this->envYolu = $this->envKlasoru.'/.env';
        file_put_contents($this->envYolu, "APP_NAME=Deneme\nTEST_YONETICI_SIFRE=eski\nDB_PASSWORD=dokunma\n");
        app()->useEnvironmentPath($this->envKlasoru);
    }

    protected function tearDown(): void
    {
        @unlink($this->envYolu);
        @rmdir($this->envKlasoru);
        parent::tearDown();
    }

    private function calistir(array $p = []): array
    {
        $kod = Artisan::call('v2:test-hesaplari', $p + ['--no-interaction' => true]);

        return [$kod, Artisan::output()];
    }

    /** @return array<string, string> */
    private function env(): array
    {
        $d = [];
        foreach (file($this->envYolu, FILE_IGNORE_NEW_LINES) as $s) {
            [$k, $v] = array_pad(explode('=', $s, 2), 2, '');
            $d[$k] = $v;
        }

        return $d;
    }

    private function testPersonelleri()
    {
        return DB::table('salon_personelleri')->where('personel_adi', 'like', 'TEST %')->orderBy('id')->get();
    }

    private function gercekKayitlarDegismedi(): void
    {
        $this->assertSame(['5321112233', 'Gercek Sahip', 'sahip-hash'], array_values((array) DB::table('isletmeyetkilileri')->where('id', 1)->first(['gsm1', 'name', 'password'])));
        $this->assertSame('ayse-hash', DB::table('isletmeyetkilileri')->where('id', 2)->value('password'));
        $this->assertSame(1, DB::table('model_has_roles')->where('salon_id', self::SALON)->where('role_id', 1)->count(), 'ikinci rol 1 eklenmez');
        $this->assertSame(1, DB::table('salon_personelleri')->where('role_id', 1)->count());
        $this->assertSame('1', (string) DB::table('salon_personelleri')->where('id', 10)->value('aktif'));
        $this->assertSame('1', (string) DB::table('salon_personelleri')->where('id', 11)->value('aktif'));
        $this->assertSame('musteri-hash', DB::table('users')->where('id', 100)->value('password'));
        $this->assertSame('1', (string) DB::table('musteri_portfoy')->where('user_id', 100)->value('aktif'));
    }

    public function test_hesaplari_acar_bildirim_yazmaz_sifreyi_yalniz_env_ye_yazar(): void
    {
        [$kod, $cikti] = $this->calistir();

        $this->assertSame(0, $kod, $cikti);
        $p = $this->testPersonelleri();
        $this->assertCount(3, $p);
        $this->assertSame(['TEST Yönetici', 'TEST Sekreter', 'TEST Personel'], $p->pluck('personel_adi')->all());
        $this->assertSame(['5009990002', '5009990003', '5009990004'], $p->pluck('cep_telefon')->all());
        $this->assertSame(['3', '4', '5'], $p->pluck('role_id')->map(fn ($r) => (string) $r)->all());
        $this->assertSame(['0', '0', '0'], $p->pluck('takvimde_gorunsun')->map(fn ($v) => (string) (int) $v)->all(), 'takvimi kirletmez');
        foreach ($p as $satir) {
            $this->assertSame((string) $satir->role_id, (string) DB::table('model_has_roles')->where('model_id', $satir->yetkili_id)->where('salon_id', self::SALON)->value('role_id'));
        }
        $this->assertSame(['yonetici', 'sekreter', 'personel'], $p->map(fn ($s) => DB::table('personel_yetki_ayarlari')->where('personel_id', $s->id)->value('sablon'))->all());

        $musteri = DB::table('users')->where('cep_telefon', '5009990005')->first();
        $this->assertSame('TEST Müşteri', $musteri->name);
        $this->assertSame('1', (string) DB::table('musteri_portfoy')->where('salon_id', self::SALON)->where('user_id', $musteri->id)->value('aktif'));

        // Hesap sahibi atlanir; gercek kayitlar degismez.
        $this->assertSame(0, DB::table('isletmeyetkilileri')->where('gsm1', '5009990001')->count());
        $this->assertStringContainsString('ATLANDI', $cikti);
        $this->gercekKayitlarDegismedi();

        // Sifre / kayit mesaji kuyruga yazilmaz.
        $this->assertSame(0, DB::table('v2_bildirim_kuyrugu')->whereIn('tur', ['personel_sifre', 'musteri_kayit', 'sms', 'whatsapp_mesaj'])->count());

        // .env: anahtarlar yazildi, diger satirlar korundu, sifreler gecerli ve ekranda yok.
        $env = $this->env();
        $this->assertSame('Deneme', $env['APP_NAME']);
        $this->assertSame('dokunma', $env['DB_PASSWORD']);
        $this->assertArrayNotHasKey('TEST_HESAP_SAHIBI_SIFRE', $env);
        $this->assertSame(1, substr_count(file_get_contents($this->envYolu), 'TEST_YONETICI_SIFRE='), 'var olan anahtar guncellenir');
        foreach (['YONETICI' => '5009990002', 'SEKRETER' => '5009990003', 'PERSONEL' => '5009990004'] as $k => $tel) {
            $this->assertSame($tel, $env["TEST_{$k}_TEL"]);
            $this->assertGreaterThanOrEqual(16, strlen($env["TEST_{$k}_SIFRE"]));
            $this->assertStringNotContainsString($env["TEST_{$k}_SIFRE"], $cikti);
            $this->assertTrue(Hash::check($env["TEST_{$k}_SIFRE"], DB::table('isletmeyetkilileri')->where('gsm1', $tel)->value('password')));
        }
        $this->assertTrue(Hash::check($env['TEST_MUSTERI_SIFRE'], $musteri->password));
        $this->assertStringNotContainsString($env['TEST_MUSTERI_SIFRE'], $cikti);
        $this->assertStringContainsString(".env'ye yazıldı", $cikti);
    }

    public function test_ikinci_calistirma_yeni_hesap_acmaz_sifre_korunur(): void
    {
        $this->calistir();
        $ilkEnv = $this->env();
        $sayilar = fn () => [
            DB::table('isletmeyetkilileri')->count(), DB::table('salon_personelleri')->count(),
            DB::table('users')->count(), DB::table('musteri_portfoy')->count(), DB::table('model_has_roles')->count(),
        ];
        $ilk = $sayilar();

        [$kod, $cikti] = $this->calistir();

        $this->assertSame(0, $kod, $cikti);
        $this->assertSame($ilk, $sayilar());
        $this->assertSame($ilkEnv, $this->env(), 'sifreler degismez');
        $this->assertStringContainsString('GÜNCELLENDİ', $cikti);
        $this->assertStringNotContainsString('AÇILDI', $cikti);
        $this->gercekKayitlarDegismedi();
    }

    public function test_sil_yalniz_kendi_hesaplarini_kaldirir_ve_yeniden_acilabilir(): void
    {
        $this->calistir();
        $sifreler = $this->env();

        [$kod, $cikti] = $this->calistir(['--sil' => true]);

        $this->assertSame(0, $kod, $cikti);
        foreach ($this->testPersonelleri() as $s) {
            $this->assertSame(['1', '0'], [(string) (int) $s->arsivli, (string) (int) $s->aktif]);
            $this->assertFalse(Hash::check($sifreler['TEST_YONETICI_SIFRE'], DB::table('isletmeyetkilileri')->where('id', $s->yetkili_id)->value('password')));
        }
        $musteri = DB::table('users')->where('cep_telefon', '5009990005')->first();
        $this->assertSame('0', (string) DB::table('musteri_portfoy')->where('user_id', $musteri->id)->value('aktif'));
        $this->assertFalse(Hash::check($sifreler['TEST_MUSTERI_SIFRE'], $musteri->password));
        $this->gercekKayitlarDegismedi();

        $env = $this->env();
        $this->assertSame(['APP_NAME', 'DB_PASSWORD'], array_values(array_filter(array_keys($env), fn ($k) => $k !== '')));

        // Yeniden calistirma ayni satirlari geri acar (yeni satir yok).
        $personelSayisi = DB::table('salon_personelleri')->count();
        [$kod] = $this->calistir();
        $this->assertSame(0, $kod);
        $this->assertSame($personelSayisi, DB::table('salon_personelleri')->count());
        $this->assertSame(['0', '1'], [(string) (int) $this->testPersonelleri()->first()->arsivli, (string) (int) $this->testPersonelleri()->first()->aktif]);
        $this->assertSame('1', (string) DB::table('musteri_portfoy')->where('user_id', $musteri->id)->value('aktif'));
    }

    public function test_salon_20_disi_reddedilir(): void
    {
        $once = DB::table('salon_personelleri')->count();

        [$kod, $cikti] = $this->calistir(['--salon' => self::DIGER_SALON]);
        $this->assertSame(1, $kod);
        $this->assertStringContainsString('--zorla', $cikti);

        [$kod] = $this->calistir(['--salon' => self::DIGER_SALON, '--sil' => true]);
        $this->assertSame(1, $kod);

        $this->assertSame($once, DB::table('salon_personelleri')->count());
        $this->assertArrayNotHasKey('TEST_PERSONEL_TEL', $this->env());
    }

    public function test_dry_run_hicbir_sey_yazmaz(): void
    {
        $envOnce = file_get_contents($this->envYolu);

        [$kod, $cikti] = $this->calistir(['--dry-run' => true]);

        $this->assertSame(0, $kod, $cikti);
        $this->assertStringContainsString('[dry-run]', $cikti);
        $this->assertCount(0, $this->testPersonelleri());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame($envOnce, file_get_contents($this->envYolu));
    }

    public function test_test_numarasi_yabanci_bir_hesaptaysa_hicbir_sey_yapmaz(): void
    {
        DB::table('isletmeyetkilileri')->insert(['id' => 50, 'gsm1' => '5009990003', 'name' => 'Biri']);

        [$kod, $cikti] = $this->calistir();

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('TEST olmayan', $cikti);
        $this->assertCount(0, $this->testPersonelleri());
        $this->assertSame('Biri', DB::table('isletmeyetkilileri')->where('id', 50)->value('name'));
    }
}
