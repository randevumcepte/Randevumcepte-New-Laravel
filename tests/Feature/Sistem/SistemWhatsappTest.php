<?php

namespace Tests\Feature\Sistem;

use App\Services\Oturum\PlatformSms;
use App\Services\Whatsapp\Kopru\KopruCevabi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use App\Support\JsonAyarDosyasi;
use Mockery;
use Tests\TestCase;

/** Sistem WhatsApp: yalniz super_admin, kopru sahte. */
class SistemWhatsappTest extends TestCase
{
    use EntegrasyonKurulum;

    private const KOK = '/api/v2/sistem/sistem-whatsapp';

    private $kopru;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ortamKur();
        $this->kopru = Mockery::mock(WhatsappKoprusu::class);
        $this->app->instance(WhatsappKoprusu::class, $this->kopru);
        $sms = Mockery::mock(PlatformSms::class);
        $sms->shouldReceive('gonder')->andReturn(true);
        $this->app->instance(PlatformSms::class, $sms);
    }

    public function test_super_admin_disi_hic_giremez(): void
    {
        foreach (['yonetici', 'destek', 'izleyici'] as $rol) {
            $this->girisYap($rol);
            $this->getJson(self::KOK)->assertStatus(403);
            $this->getJson(self::KOK.'/durum')->assertStatus(403);
            $this->postJson(self::KOK.'/cikis')->assertStatus(403);
        }
    }

    public function test_ayar_normalize_edilir_ve_mevcut_anahtar_korunur(): void
    {
        JsonAyarDosyasi::guncelle((string) config('sistem.wa_ayar_dosyasi'), ['session_id' => 'sistem-123']);
        $this->girisYap('super_admin');

        $this->putJson(self::KOK.'/ayar', ['numara' => '05321112233; 5321112244', 'gonderen_numara' => '0533 000 00 00', 'aktif' => true])
            ->assertOk()->assertJsonPath('veri.numara', '905321112233, 905321112244')->assertJsonPath('veri.gonderen_numara', '905330000000');

        $this->assertSame('sistem-123', JsonAyarDosyasi::oku((string) config('sistem.wa_ayar_dosyasi'))['session_id']);
        $this->assertTrue($this->denetimVar('sistem_whatsapp_ayar'));
    }

    public function test_durum_servis_kapali_ve_bagli_numara_uyarisi(): void
    {
        JsonAyarDosyasi::guncelle((string) config('sistem.wa_ayar_dosyasi'), ['numara' => '905321112233', 'aktif' => true]);
        $this->girisYap('super_admin');

        $this->kopru->shouldReceive('durum')->once()->with('sistem')->andReturn(new KopruCevabi(0));
        $this->getJson(self::KOK.'/durum')->assertOk()->assertJsonPath('veri.servis_acik', false)->assertJsonPath('veri.bagli', false);

        $this->kopru->shouldReceive('durum')->once()->andReturn(new KopruCevabi(200, ['connected' => true, 'status' => 'connected', 'phone' => '905321112233']));
        $uyari = $this->getJson(self::KOK.'/durum')->assertJsonPath('veri.bagli', true)->json('veri.uyari');
        $this->assertStringContainsString('aynı', $uyari);
    }

    public function test_qr_data_uri_oldugu_gibi_doner_yoksa_teshis(): void
    {
        $this->girisYap('super_admin');
        $this->kopru->shouldReceive('qr')->once()->andReturn(new KopruCevabi(200, ['qr' => 'data:image/png;base64,AAA']));
        $this->getJson(self::KOK.'/qr')->assertJsonPath('veri.qr', 'data:image/png;base64,AAA');

        $this->kopru->shouldReceive('qr')->once()->andReturn(new KopruCevabi(404, ['error' => 'no-qr']));
        $this->assertStringContainsString('no-qr', $this->getJson(self::KOK.'/qr')->assertJsonPath('veri.qr', null)->json('veri.teshis'));
    }

    public function test_eslestirme_once_baslatir_kodu_bicimlendirir(): void
    {
        $this->girisYap('super_admin');
        $this->kopru->shouldReceive('baslat')->once()->with('sistem')->andReturn(new KopruCevabi(200));
        $this->kopru->shouldReceive('eslestir')->once()->with('sistem', '905321112233')->andReturn(new KopruCevabi(200, ['code' => 'abcd1234']));

        $this->postJson(self::KOK.'/eslestir', ['telefon' => '90 532 111 22 33'])->assertOk()->assertJsonPath('veri.gosterim', 'ABCD-1234');
        $this->postJson(self::KOK.'/eslestir', ['telefon' => '123'])->assertStatus(422);
    }

    public function test_cikis_yeni_oturum_kimligine_gecer(): void
    {
        $this->girisYap('super_admin');
        $this->kopru->shouldReceive('cikis')->once()->with('sistem')->andReturn(new KopruCevabi(200));

        $this->postJson(self::KOK.'/cikis')->assertOk();

        $this->assertStringStartsWith('sistem-', JsonAyarDosyasi::oku((string) config('sistem.wa_ayar_dosyasi'))['session_id']);
        $this->assertTrue($this->denetimVar('sistem_whatsapp_cikis'));
    }

    public function test_test_mesaji_gonderen_aliciyla_ayniysa_gitmez(): void
    {
        JsonAyarDosyasi::guncelle((string) config('sistem.wa_ayar_dosyasi'), ['numara' => '905321112233', 'aktif' => true]);
        $this->girisYap('super_admin');
        $this->kopru->shouldReceive('durum')->andReturn(new KopruCevabi(200, ['status' => 'connected', 'phone' => '905321112233']));
        $this->kopru->shouldNotReceive('gonder');

        $this->postJson(self::KOK.'/test')->assertStatus(422);
    }

    public function test_test_mesaji_alici_basina_ozet_doner(): void
    {
        JsonAyarDosyasi::guncelle((string) config('sistem.wa_ayar_dosyasi'), ['numara' => '905321112233', 'aktif' => true]);
        $this->girisYap('super_admin');
        $this->kopru->shouldReceive('durum')->andReturn(new KopruCevabi(200, ['status' => 'connected', 'phone' => '905330000000']));
        $this->kopru->shouldReceive('gonder')->once()->andReturn(new KopruCevabi(200));

        $ozet = $this->postJson(self::KOK.'/test')->assertOk()->json('veri.ozet');
        $this->assertStringContainsString('5321112233: WA ✓ | SMS ✓', $ozet);
    }
}
