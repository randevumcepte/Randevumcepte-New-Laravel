<?php

namespace Tests\Feature\Sistem;

use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Support\JsonAyarDosyasi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** AI kredi: JSON ayar eski monolitle ortak, kalan tum zaman harcamadan. */
class AiKrediTest extends TestCase
{
    use EntegrasyonKurulum;

    private const KOK = '/api/v2/sistem/ai-kredi';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ortamKur();
    }

    private function kullanimTablosu(): void
    {
        Schema::create('ai_kullanim', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id')->nullable();
            $t->string('tur')->default('sohbet');
            $t->string('model')->nullable();
            $t->integer('girdi_token')->default(0);
            $t->integer('cikti_token')->default(0);
            $t->decimal('maliyet_usd', 12, 6)->default(0);
            $t->integer('cache')->default(0);
            $t->integer('basarili')->default(1);
            $t->timestamp('created_at')->nullable();
        });
    }

    private function ayarOku(): array
    {
        return JsonAyarDosyasi::oku((string) config('sistem.ai_ayar_dosyasi'));
    }

    public function test_tablo_yoksa_varsayilan_ayarla_acilir(): void
    {
        $this->girisYap('izleyici');

        $this->getJson(self::KOK)->assertOk()->assertJsonPath('veri.tablo_yok', true)
            ->assertJsonPath('veri.ayar.kur', 40)->assertJsonPath('veri.ayar.esik_usd', 1)
            ->assertJsonPath('veri.kredi.girildi', false)->assertJsonPath('veri.izinler.yaz', false);
    }

    public function test_rapor_donem_ve_kalan_tum_zamandan(): void
    {
        $this->kullanimTablosu();
        DB::table('salonlar')->insert(['id' => 7, 'salon_adi' => 'Yedi']);
        DB::table('ai_kullanim')->insert([
            ['salon_id' => 7, 'tur' => 'sohbet', 'girdi_token' => 100, 'cikti_token' => 50, 'maliyet_usd' => 2.0, 'cache' => 0, 'created_at' => now()->toDateTimeString()],
            ['salon_id' => 7, 'tur' => 'sohbet', 'girdi_token' => 0, 'cikti_token' => 0, 'maliyet_usd' => 0, 'cache' => 1, 'created_at' => now()->toDateTimeString()],
            ['salon_id' => null, 'tur' => 'niyet', 'girdi_token' => 10, 'cikti_token' => 10, 'maliyet_usd' => 3.0, 'cache' => 0, 'created_at' => now()->subDays(60)->toDateTimeString()],
        ]);
        JsonAyarDosyasi::guncelle((string) config('sistem.ai_ayar_dosyasi'), ['yuklenen_usd' => 5.5]);
        $this->girisYap('yonetici');

        $r = $this->getJson(self::KOK.'?gun=30')->assertOk()
            ->assertJsonPath('veri.ozet.cagri', 2)->assertJsonPath('veri.ozet.gercek', 1)->assertJsonPath('veri.ozet.cache', 1)
            ->assertJsonPath('veri.kredi.azaldi', true)->assertJsonPath('veri.izinler.yaz', true)
            ->assertJsonPath('veri.turler.0.ad', 'Sohbet')->assertJsonCount(1, 'veri.gunluk');
        $this->assertEqualsWithDelta(0.5, $r->json('veri.kalan_usd'), 0.0001);
        $this->assertEqualsWithDelta(2.0, $r->json('veri.ozet.cache_tasarruf_usd'), 0.0001);

        $this->getJson(self::KOK.'?gun=0')->assertJsonPath('veri.ozet.cagri', 3);
        $this->getJson(self::KOK.'?gun=5')->assertStatus(422);

        $this->getJson(self::KOK.'/salonlar?gun=0')->assertOk()->assertJsonPath('veri.total', 2)->assertJsonPath('veri.data.0.salon_adi', '— (sistem)');
        $this->getJson(self::KOK.'/salonlar?gun=0&ara=Yedi')->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.salon_id', 7);
    }

    public function test_yazma_rol_kapisi(): void
    {
        foreach (['destek', 'izleyici'] as $rol) {
            $this->girisYap($rol);
            $this->putJson(self::KOK.'/ayar', ['yuklenen' => 1])->assertStatus(403);
            $this->postJson(self::KOK.'/ekle', ['eklenen' => 1])->assertStatus(403);
            $this->postJson(self::KOK.'/test-alarm')->assertStatus(403);
        }
    }

    public function test_ayar_ve_ekle_son_uyariyi_sifirlar_diger_anahtarlari_korur(): void
    {
        JsonAyarDosyasi::guncelle((string) config('sistem.ai_ayar_dosyasi'), ['son_uyari' => '2026-01-01', 'eski_alan' => 'kalsin', 'kur' => 35]);
        $this->girisYap('yonetici');

        $this->putJson(self::KOK.'/ayar', ['yuklenen' => 10, 'esik' => 2])->assertOk();
        $a = $this->ayarOku();
        $this->assertNull($a['son_uyari']);
        $this->assertSame('kalsin', $a['eski_alan']);
        $this->assertEquals(35, $a['kur']);
        $this->assertEquals(2, $a['esik_usd']);
        $this->assertNotNull($a['guncelleme']);

        $this->postJson(self::KOK.'/ekle', ['eklenen' => 0])->assertStatus(422);
        $this->postJson(self::KOK.'/ekle', ['eklenen' => 5.5])->assertOk()->assertJsonPath('veri.yuklenen_usd', 15.5);
        $this->assertTrue($this->denetimVar('ai_kredi_ayar'));
        $this->assertTrue($this->denetimVar('ai_kredi_ekle'));
    }

    public function test_test_alarmi_sistem_bildirimine_gider(): void
    {
        $this->kullanimTablosu();
        $bildirim = Mockery::mock(SistemBildirimServisi::class);
        $bildirim->shouldReceive('gonder')->once()->withArgs(fn ($m) => str_contains($m, 'AI KREDİSİ AZALDI'))->andReturn(['ok' => true]);
        $bildirim->shouldReceive('gonder')->once()->andReturn(['ok' => false]);
        $this->app->instance(SistemBildirimServisi::class, $bildirim);
        $this->girisYap('super_admin');

        $this->postJson(self::KOK.'/test-alarm')->assertOk();
        $this->postJson(self::KOK.'/test-alarm')->assertStatus(422);
        $this->assertTrue($this->denetimVar('ai_kredi_test_alarm'));
    }
}
