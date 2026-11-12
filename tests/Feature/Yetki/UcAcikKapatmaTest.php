<?php

namespace Tests\Feature\Yetki;

use App\Models\IsletmeYetkilileri;
use App\Services\Seslendirme\SeslendirmeServisi;
use App\Services\Tahsilat\AlacakServisi;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Uc yetki taramasinda bulunan aciklar (seslendirme, telefon kontrol, alacak, tahsilat): sira kimlik → kapsam → yetki →
 * yazma bayragi; baska salonun kaydi ile olmayan kayit ayni 404; ucretli/toplu uclarda kullanici + salon kotasi (429).
 * Izole sqlite; Google TTS ve alacak/tahsilat yazicilari Mockery ile sahte (gercek dis istek ya da yazma yok).
 */
class UcAcikKapatmaTest extends TestCase
{
    private const SALON = 100;

    private const BASKA_SALON = 200;

    private const SAHIP = 1;        // salon 100 hesap sahibi (tam yetki)

    private const YETKISIZ = 5;     // salon 100 personeli, tum anahtarlar kapali

    private const BASKA_SAHIP = 2;  // salon 200 hesap sahibi

    private const ADISYON = 10;     // salon 100

    private const BASKA_ADISYON = 20; // salon 200

    private const TAHSILAT = 30;    // salon 100

    private const BASKA_TAHSILAT = 40; // salon 200

    private const ALACAK = 50;      // salon 100

    private const BASKA_ALACAK = 60; // salon 200

    private const YOK = 999999;

    /** @var \Mockery\MockInterface */
    private $tts;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'uc_acik_sqlite',
            'database.connections.uc_acik_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'tahsilat.yazma_aktif' => false,
        ]);
        DB::purge('uc_acik_sqlite');
        DB::setDefaultConnection('uc_acik_sqlite');

        $this->semaKur();
        $this->veriKur();
        $this->sahteleriKur();
    }

    private function semaKur(): void
    {
        $tablolar = [
            'isletmeyetkilileri' => ['name'],
            'salonlar' => ['salon_adi', 'uyelik_turu', 'studyo_modu', 'uyelik_bitis_tarihi'],
            'salon_personelleri' => ['yetkili_id', 'salon_id', 'role_id', 'personel_adi', 'aktif'],
            'model_has_roles' => ['role_id', 'model_type', 'model_id', 'salon_id'],
            'personel_yetki_ayarlari' => ['personel_id', 'salon_id', 'sablon', 'ayarlar', 'created_at', 'updated_at'],
            'users' => ['name', 'cep_telefon'],
            'musteri_portfoy' => ['salon_id', 'user_id', 'aktif'],
            'adisyonlar' => ['salon_id', 'user_id'],
            'tahsilatlar' => ['salon_id', 'adisyon_id', 'tutar'],
        ];
        foreach ($tablolar as $ad => $kolonlar) {
            Schema::create($ad, function (Blueprint $t) use ($kolonlar) {
                $t->bigIncrements('id');
                foreach ($kolonlar as $k) {
                    preg_match('/_id$|^aktif$|^uyelik_turu$|^studyo_modu$/', $k) ? $t->integer($k)->nullable() : $t->text($k)->nullable();
                }
            });
        }
    }

    private function veriKur(): void
    {
        DB::table('salonlar')->insert([
            ['id' => self::SALON, 'salon_adi' => 'Bizim', 'uyelik_turu' => 3, 'studyo_modu' => 0, 'uyelik_bitis_tarihi' => '2099-12-31'],
            ['id' => self::BASKA_SALON, 'salon_adi' => 'Baska', 'uyelik_turu' => 3, 'studyo_modu' => 0, 'uyelik_bitis_tarihi' => '2099-12-31'],
        ]);
        $this->hesapKur(self::SAHIP, 1, self::SALON);
        $this->hesapKur(self::YETKISIZ, 5, self::SALON);
        $this->hesapKur(self::BASKA_SAHIP, 1, self::BASKA_SALON);

        // Bos yetki sablonu: her anahtar kapali, kisit anahtarlari acik (en dar hesap).
        $kisit = YetkiSabitleri::kisitAnahtarlari();
        $ayarlar = [];
        foreach (YetkiSabitleri::tumAnahtarlar() as $anahtar) {
            $ayarlar[$anahtar] = in_array($anahtar, $kisit, true);
        }
        DB::table('personel_yetki_ayarlari')->insert(['personel_id' => self::YETKISIZ * 10, 'salon_id' => self::SALON, 'sablon' => 'ozel',
            'ayarlar' => json_encode($ayarlar)]);

        DB::table('users')->insert([['id' => 900, 'name' => 'Kendi', 'cep_telefon' => '5321234567'], ['id' => 901, 'name' => 'Baska', 'cep_telefon' => '5559876543']]);
        DB::table('musteri_portfoy')->insert([
            ['salon_id' => self::SALON, 'user_id' => 900, 'aktif' => 1],
            ['salon_id' => self::BASKA_SALON, 'user_id' => 901, 'aktif' => 1],
        ]);
        DB::table('adisyonlar')->insert([
            ['id' => self::ADISYON, 'salon_id' => self::SALON, 'user_id' => 900],
            ['id' => self::BASKA_ADISYON, 'salon_id' => self::BASKA_SALON, 'user_id' => 901],
        ]);
        DB::table('tahsilatlar')->insert([
            ['id' => self::TAHSILAT, 'salon_id' => self::SALON, 'adisyon_id' => self::ADISYON, 'tutar' => '100'],
            ['id' => self::BASKA_TAHSILAT, 'salon_id' => self::BASKA_SALON, 'adisyon_id' => self::BASKA_ADISYON, 'tutar' => '100'],
        ]);
    }

    /** Personel id = yetkili id x 10. */
    private function hesapKur(int $yetkili, int $rol, int $salon): void
    {
        DB::table('isletmeyetkilileri')->insert(['id' => $yetkili, 'name' => "Yetkili $yetkili"]);
        DB::table('salon_personelleri')->insert(['id' => $yetkili * 10, 'yetkili_id' => $yetkili, 'salon_id' => $salon, 'role_id' => $rol,
            'personel_adi' => "Personel $yetkili", 'aktif' => 1]);
        DB::table('model_has_roles')->insert(['role_id' => $rol, 'model_type' => 'isletme', 'model_id' => $yetkili, 'salon_id' => $salon]);
    }

    private function sahteleriKur(): void
    {
        // Google TTS gercekten cagrilmaz.
        $this->tts = Mockery::mock(SeslendirmeServisi::class);
        $this->app->instance(SeslendirmeServisi::class, $this->tts);

        $alacak = Mockery::mock(AlacakServisi::class);
        $alacak->shouldReceive('alacakSalonId')->andReturnUsing(fn (int $id) => [self::ALACAK => self::SALON, self::BASKA_ALACAK => self::BASKA_SALON][$id] ?? null);
        $alacak->shouldReceive('sil')->andReturn(['iptal' => true]);
        $alacak->shouldReceive('olustur')->andReturn(['id' => 1]);
        $this->app->instance(AlacakServisi::class, $alacak);

        $yaz = Mockery::mock(TahsilatYazServisi::class);
        $yaz->shouldReceive('planla')->andReturn(['plan' => 'tamam']);
        $this->app->instance(TahsilatYazServisi::class, $yaz);
    }

    private function giris(int $yetkili): self
    {
        $this->app->forgetInstance(YetkiServisi::class);
        $this->app->forgetInstance(GuvenlikKapisi::class);

        return $this->actingAs(IsletmeYetkilileri::find($yetkili), 'isletmeyonetim-api');
    }

    // ------------------------------------------------------------------
    // 1. Seslendirme
    // ------------------------------------------------------------------

    public function test_seslendirme_yetkisize_403_ve_google_cagrilmaz(): void
    {
        $this->tts->shouldNotReceive('uret');

        $this->giris(self::YETKISIZ)->postJson('/api/v2/seslendir', ['salon_id' => self::SALON, 'metin' => 'Merhaba'])->assertForbidden();
    }

    public function test_seslendirme_salon_zorunlu_ve_baska_salona_kapali(): void
    {
        $this->tts->shouldNotReceive('uret');

        $this->giris(self::SAHIP)->postJson('/api/v2/seslendir', ['metin' => 'Merhaba'])->assertStatus(422);
        $this->giris(self::SAHIP)->postJson('/api/v2/seslendir', ['salon_id' => self::BASKA_SALON, 'metin' => 'Merhaba'])->assertForbidden();
    }

    public function test_seslendirme_yetkiliye_imzali_adres_doner(): void
    {
        $this->tts->shouldReceive('uret')->once()->andReturn(str_repeat('a', 32).'.mp3');

        $this->giris(self::SAHIP)->postJson('/api/v2/seslendir', ['salon_id' => self::SALON, 'metin' => 'Merhaba'])
            ->assertOk()->assertJsonPath('veri.basarili', true)
            ->assertJsonPath('veri.url', fn ($url) => str_contains($url, 'signature='));
    }

    public function test_seslendirme_dakika_siniri_429(): void
    {
        $this->tts->shouldReceive('uret')->times(20)->andReturn(str_repeat('b', 32).'.mp3');

        for ($i = 0; $i < 20; $i++) {
            $this->giris(self::SAHIP)->postJson('/api/v2/seslendir', ['salon_id' => self::SALON, 'metin' => "Metin $i"])->assertOk();
        }
        $this->giris(self::SAHIP)->postJson('/api/v2/seslendir', ['salon_id' => self::SALON, 'metin' => 'Fazla'])->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // 2. Telefon kontrol
    // ------------------------------------------------------------------

    public function test_telefon_kontrol_yetkisize_403(): void
    {
        $this->giris(self::YETKISIZ)->postJson('/api/v2/musteri/telefon-kontrol', ['salon_id' => self::SALON, 'phones' => ['05321234567']])
            ->assertForbidden();
    }

    public function test_telefon_kontrol_yetkili_yalniz_kendi_portfoyunu_gorur(): void
    {
        $this->giris(self::SAHIP)->postJson('/api/v2/musteri/telefon-kontrol', ['salon_id' => self::SALON, 'phones' => ['05321234567', '05559876543']])
            ->assertOk()
            ->assertJsonPath('veri.phones.0.exists', true)
            ->assertJsonPath('veri.phones.1.exists', false);
    }

    public function test_telefon_kontrol_istek_ve_gunluk_sinir(): void
    {
        $this->giris(self::SAHIP)->postJson('/api/v2/musteri/telefon-kontrol', ['salon_id' => self::SALON, 'phones' => $this->numaralar(501)])
            ->assertStatus(422);

        for ($i = 0; $i < 4; $i++) {
            $this->giris(self::SAHIP)->postJson('/api/v2/musteri/telefon-kontrol', ['salon_id' => self::SALON, 'phones' => $this->numaralar(500)])
                ->assertOk();
        }
        $this->giris(self::SAHIP)->postJson('/api/v2/musteri/telefon-kontrol', ['salon_id' => self::SALON, 'phones' => ['05321234567']])
            ->assertStatus(429);
    }

    /** @return string[] */
    private function numaralar(int $adet): array
    {
        return array_map(fn (int $i) => '0532'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), range(1, $adet));
    }

    // ------------------------------------------------------------------
    // 3. Alacak
    // ------------------------------------------------------------------

    public function test_alacak_ekle_baska_salonun_adisyonu_olmayan_gibi_404(): void
    {
        $baska = $this->giris(self::SAHIP)->postJson('/api/v2/alacak', ['salon_id' => self::SALON, 'adisyon_id' => self::BASKA_ADISYON, 'tutar' => 50]);
        $yok = $this->giris(self::SAHIP)->postJson('/api/v2/alacak', ['salon_id' => self::SALON, 'adisyon_id' => self::YOK, 'tutar' => 50]);

        $baska->assertNotFound();
        $yok->assertNotFound();
        $this->assertSame($yok->json(), $baska->json());
    }

    public function test_alacak_ekle_yetkisize_403_bayrak_soylenmez(): void
    {
        // Yazma bayragi kapali: yetkisiz 409 degil 403 alir; adisyon bile sorulmaz (baska salonun id'si de 403).
        $this->giris(self::YETKISIZ)->postJson('/api/v2/alacak', ['salon_id' => self::SALON, 'adisyon_id' => self::ADISYON, 'tutar' => 50])->assertForbidden();
        $this->giris(self::YETKISIZ)->postJson('/api/v2/alacak', ['salon_id' => self::SALON, 'adisyon_id' => self::BASKA_ADISYON, 'tutar' => 50])->assertForbidden();
    }

    public function test_alacak_yetkili_davranisi(): void
    {
        $this->giris(self::SAHIP)->postJson('/api/v2/alacak', ['salon_id' => self::SALON, 'adisyon_id' => self::ADISYON, 'tutar' => 50])->assertStatus(409);
        $this->giris(self::SAHIP)->deleteJson('/api/v2/alacak/'.self::ALACAK)->assertStatus(409);

        config(['tahsilat.yazma_aktif' => true]);
        $this->giris(self::SAHIP)->postJson('/api/v2/alacak', ['salon_id' => self::SALON, 'adisyon_id' => self::ADISYON, 'tutar' => 50])->assertOk();
        $this->giris(self::SAHIP)->deleteJson('/api/v2/alacak/'.self::ALACAK)->assertOk();
    }

    public function test_alacak_guncelle_sil_baska_salona_404_yetkisize_403(): void
    {
        foreach (['PATCH', 'DELETE'] as $metot) {
            $baska = $this->giris(self::SAHIP)->json($metot, '/api/v2/alacak/'.self::BASKA_ALACAK, ['tutar' => 10]);
            $yok = $this->giris(self::SAHIP)->json($metot, '/api/v2/alacak/'.self::YOK, ['tutar' => 10]);
            $baska->assertNotFound();
            $this->assertSame($yok->assertNotFound()->json(), $baska->json());

            // Bayrak kapali iken yetkisiz 403 alir (409 degil).
            $this->giris(self::YETKISIZ)->json($metot, '/api/v2/alacak/'.self::ALACAK, ['tutar' => 10])->assertForbidden();
        }
    }

    // ------------------------------------------------------------------
    // 4. Tahsilat
    // ------------------------------------------------------------------

    public function test_tahsilat_ekle_baska_salonun_adisyonu_olmayan_gibi_404(): void
    {
        $govde = ['salon_id' => self::SALON, 'tutar' => 50, 'dry_run' => 1];
        $baska = $this->giris(self::SAHIP)->postJson('/api/v2/tahsilat', $govde + ['adisyon_id' => self::BASKA_ADISYON]);
        $yok = $this->giris(self::SAHIP)->postJson('/api/v2/tahsilat', $govde + ['adisyon_id' => self::YOK]);

        $baska->assertNotFound();
        $this->assertSame($yok->assertNotFound()->json(), $baska->json());
    }

    public function test_tahsilat_ekle_yetkisize_403_yetkiliye_dry_run(): void
    {
        $govde = ['salon_id' => self::SALON, 'adisyon_id' => self::ADISYON, 'tutar' => 50];
        $this->giris(self::YETKISIZ)->postJson('/api/v2/tahsilat', $govde)->assertForbidden();
        $this->giris(self::YETKISIZ)->postJson('/api/v2/tahsilat', ['adisyon_id' => self::BASKA_ADISYON] + $govde)->assertForbidden();

        $this->giris(self::SAHIP)->postJson('/api/v2/tahsilat', $govde + ['dry_run' => 1])->assertOk()->assertJsonPath('veri.plan', 'tamam');
        $this->giris(self::SAHIP)->postJson('/api/v2/tahsilat', $govde)->assertStatus(409);
    }

    public function test_tahsilat_guncelle_sil_sira_kimlik_kapsam_yetki_bayrak(): void
    {
        foreach (['PATCH', 'DELETE'] as $metot) {
            $baska = $this->giris(self::SAHIP)->json($metot, '/api/v2/tahsilat/'.self::BASKA_TAHSILAT, ['tutar' => 10]);
            $yok = $this->giris(self::SAHIP)->json($metot, '/api/v2/tahsilat/'.self::YOK, ['tutar' => 10]);
            $baska->assertNotFound();
            $this->assertSame($yok->assertNotFound()->json(), $baska->json());

            // Bayrak kapali: yetkisiz 403 (bayrak durumu sizmaz), yetkili 409.
            $this->giris(self::YETKISIZ)->json($metot, '/api/v2/tahsilat/'.self::TAHSILAT, ['tutar' => 10])->assertForbidden();
            $this->giris(self::SAHIP)->json($metot, '/api/v2/tahsilat/'.self::TAHSILAT, ['tutar' => 10])->assertStatus(409);
        }
    }

    public function test_taksit_baska_salonun_adisyonuna_404_yetkisize_403(): void
    {
        $baska = $this->giris(self::SAHIP)->postJson('/api/v2/adisyon/'.self::BASKA_ADISYON.'/taksit', []);
        $yok = $this->giris(self::SAHIP)->postJson('/api/v2/adisyon/'.self::YOK.'/taksit', []);
        $baska->assertNotFound();
        $this->assertSame($yok->assertNotFound()->json(), $baska->json());

        $this->giris(self::YETKISIZ)->postJson('/api/v2/adisyon/'.self::ADISYON.'/taksit', [])->assertForbidden();
        $this->giris(self::SAHIP)->postJson('/api/v2/adisyon/'.self::ADISYON.'/taksit', [])->assertStatus(409);
    }
}
