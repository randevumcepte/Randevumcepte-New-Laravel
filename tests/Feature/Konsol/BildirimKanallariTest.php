<?php

namespace Tests\Feature\Konsol;

use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Kanal kurallari: saglayicilar Http::fake, ag istegi yok. */
class BildirimKanallariTest extends TestCase
{
    private const TEL = '5321234567';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            foreach (['yeni_sms', 'sms_user_name', 'sms_secret', 'sms_apikey', 'sms_baslik', 'randevu_sms_hatirlatma', 'kara_liste_aktif', 'salon_adi', 'whatsapp_aktif',
                'whatsapp_durum', 'whatsapp_numara', 'whatsapp_kontor', 'whatsapp_deneme_bitis', 'whatsapp_saglayici', 'whatsapp_paylasilan_salon_id', 'whatsapp_baglanti_tarihi',
                'whatsapp_warmup_baslangic', 'whatsapp_gunluk_limit', 'whatsapp_son_hata'] as $kolon) {
                $t->string($kolon)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('cep_telefon')->nullable();
        });
        Schema::create('musteri_portfoy', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            $t->integer('salon_id');
            $t->integer('kara_liste')->default(0);
        });
        Schema::create('sms_iletim_raporlari', function (Blueprint $t) {
            $t->id();
            foreach (['salon_id', 'tur', 'aciklama', 'rapor_id', 'adet', 'kredi', 'durum'] as $kolon) {
                $t->string($kolon)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('whatsapp_gonderim_loglari', function (Blueprint $t) {
            $t->id();
            foreach (['salon_id', 'user_id', 'randevu_id', 'telefon', 'mesaj', 'durum', 'hata', 'provider', 'gonderim_tipi', 'mesaj_id', 'gonderim_tarihi'] as $kolon) {
                $t->string($kolon)->nullable();
            }
            $t->timestamps();
        });
        config([
            'sms.efetech.gonderim_url' => 'https://efetech.test/gonder',
            'sms.efetech.rapor_url' => 'https://efetech.test/rapor',
            'sms.voicetelekom.gonderim_url' => 'http://vt.test/sms/create',
            'whatsapp.kopru_url' => 'http://kopru.test',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['salonlar', 'users', 'musteri_portfoy', 'sms_iletim_raporlari', 'whatsapp_gonderim_loglari'] as $tablo) {
            Schema::dropIfExists($tablo);
        }
        parent::tearDown();
    }

    private function salon(array $alan = []): int
    {
        return (int) DB::table('salonlar')->insertGetId($alan + ['sms_baslik' => 'BASLIK', 'sms_apikey' => 'ANAHTAR', 'yeni_sms' => 0, 'whatsapp_deneme_bitis' => '2099-01-01']);
    }

    private function efetechBasarili(int $durum = 11): void
    {
        Http::fake([
            'efetech.test/gonder' => Http::response(['response' => ['message' => ['id' => 77, 'count' => 1, 'total_price' => 1.5]]]),
            'efetech.test/rapor'  => Http::response(['response' => ['message' => ['status' => $durum]]]),
        ]);
    }

    private function mesajlar(): array
    {
        return [['to' => self::TEL, 'message' => 'merhaba']];
    }

    private function sms(): SmsKanali
    {
        return app(SmsKanali::class);
    }

    public function test_api_bildirimli_efetech_rapor_yazar_ve_aciklama_eski_ayracla(): void
    {
        $this->efetechBasarili(11);

        $this->sms()->apiBildirimli($this->mesajlar(), false, 1, false, $this->salon());

        $r = DB::table('sms_iletim_raporlari')->first();
        $this->assertSame(['merhaba\n', '77', '1', '1.5', '11', '1'], [$r->aciklama, $r->rapor_id, $r->adet, $r->kredi, $r->durum, $r->tur]);
    }

    public function test_dogrulama_bayragi_rapor_yazmaz_ve_durum_sormaz(): void
    {
        $this->efetechBasarili();

        $this->sms()->apiBildirimli($this->mesajlar(), false, 1, true, $this->salon());

        $this->assertSame(0, DB::table('sms_iletim_raporlari')->count());
        Http::assertSentCount(1);
    }

    public function test_api_bildirimli_yeni_sms_salonda_da_efetechtir_eski_davranis(): void
    {
        $this->efetechBasarili();

        $this->sms()->apiBildirimli($this->mesajlar(), false, 1, false, $this->salon(['yeni_sms' => 1]));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'efetech.test/gonder'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'vt.test'));
    }

    public function test_api_bildirimli_anahtarsiz_salonda_sessizce_gitmez_eski_davranis(): void
    {
        Http::fake();

        $this->assertNull($this->sms()->apiBildirimli($this->mesajlar(), false, 1, false, $this->salon(['sms_apikey' => null])));

        Http::assertNothingSent();
    }

    public function test_efetech_ulasilamazsa_hata_firlar_outbox_yeniden_dener(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);
        $this->sms()->apiBildirimli($this->mesajlar(), false, 1, false, $this->salon());
    }

    public function test_panel_whatsapp_kuyruga_aldiysa_sms_gitmez_log_gonderen_salona(): void
    {
        Http::fake(['kopru.test/*' => Http::response(['ok' => true], 202)]);
        $salonId = $this->salon(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);

        $this->sms()->panelBildirimli($this->mesajlar(), false, 1, false, $salonId);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === "http://kopru.test/session/{$salonId}/send" && $r['to'] === '905321234567' && $r['urgent'] === false);
        $l = DB::table('whatsapp_gonderim_loglari')->first();
        $this->assertSame([(string) $salonId, 'transaksiyonel', 'baileys'], [$l->salon_id, $l->gonderim_tipi, $l->provider]);
    }

    public function test_panel_whatsapp_basarisizsa_sms_duser_log_hatali(): void
    {
        $this->efetechBasarili();
        Http::fake(['kopru.test/*' => Http::response(['error' => 'x'], 500)]);
        $salonId = $this->salon(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);

        $this->sms()->panelBildirimli($this->mesajlar(), false, 1, false, $salonId);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'efetech.test/gonder'));
        $this->assertSame(['2', 'x'], [DB::table('whatsapp_gonderim_loglari')->value('durum'), DB::table('whatsapp_gonderim_loglari')->value('hata')]);
        $this->assertSame('0', DB::table('sms_iletim_raporlari')->value('durum'));
    }

    public function test_kara_listedeki_user_id_panelde_hic_kanala_gitmez(): void
    {
        Http::fake();
        $salonId = $this->salon(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);
        DB::table('musteri_portfoy')->insert(['user_id' => 5, 'salon_id' => $salonId, 'kara_liste' => 1]);

        $this->sms()->panelBildirimli([['to' => self::TEL, 'message' => 'x', 'user_id' => 5]], false, 1, false, $salonId);

        Http::assertNothingSent();
    }

    public function test_paylasilan_oturum_ust_salondan_gider_log_alt_salona(): void
    {
        Http::fake(['kopru.test/*' => Http::response([], 202)]);
        $ust = $this->salon(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);
        $alt = $this->salon(['whatsapp_paylasilan_salon_id' => $ust]);
        $kanal = app(WhatsappKanali::class);

        $this->assertTrue($kanal->gonder($kanal->salon($alt), self::TEL, 'x')['ok']);

        Http::assertSent(fn (Request $r) => $r->url() === "http://kopru.test/session/{$ust}/send");
        $this->assertSame((string) $alt, DB::table('whatsapp_gonderim_loglari')->value('salon_id'));
    }

    public function test_gunluk_tavan_doluysa_gitmez_guvenlik_tipi_muaf(): void
    {
        Http::fake(['kopru.test/*' => Http::response([], 202)]);
        $salonId = $this->salon(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected', 'whatsapp_gunluk_limit' => 1]);
        DB::table('whatsapp_gonderim_loglari')->insert(['salon_id' => $salonId, 'durum' => 1, 'gonderim_tarihi' => now()->toDateTimeString()]);
        $kanal = app(WhatsappKanali::class);

        $this->assertSame('daily-cap-reached', $kanal->gonder($kanal->salon($salonId), self::TEL, 'x')['error']);
        $this->assertTrue($kanal->gonder($kanal->salon($salonId), self::TEL, 'x', null, null, null, false, 'sifre_sifirlama')['ok']);
    }

    public function test_kopru_yetkisiz_derse_salon_disconnected_olur(): void
    {
        Http::fake(['kopru.test/*' => Http::response(['error' => 'unauthorized'], 401)]);
        $salonId = $this->salon(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);
        $kanal = app(WhatsappKanali::class);

        $this->assertFalse($kanal->gonder($kanal->salon($salonId), self::TEL, 'x')['ok']);

        $this->assertSame('disconnected', DB::table('salonlar')->where('id', $salonId)->value('whatsapp_durum'));
    }

    public function test_gonder_voicetelekom_tek_paket_ilk_metin_eski_davranis(): void
    {
        Http::fake(['vt.test/*' => Http::response(['err' => null, 'data' => ['pkgID' => 'P1']])]);

        $this->sms()->gonder($this->salon(['yeni_sms' => 1, 'sms_user_name' => 'k', 'sms_secret' => 's']), [
            ['to' => '5321111111', 'message' => 'ilk'], ['to' => '5322222222', 'message' => 'ikinci'],
        ]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['content'] === 'ilk' && $r['numbers'] === ['5321111111', '5322222222'] && $r['title'] === 'Bildirim' && ! isset($r['customID']));
    }

    public function test_gonder2_voicetelekom_mesaj_basina_ayri_paket(): void
    {
        Http::fake(['vt.test/*' => Http::response(['err' => null, 'data' => ['pkgID' => 'P1']])]);

        $this->sms()->gonder2([['to' => '5321111111', 'message' => 'ilk'], ['to' => '5322222222', 'message' => 'ikinci']], false, 1, false,
            $this->salon(['yeni_sms' => 1, 'sms_user_name' => 'k', 'sms_secret' => 's']));

        Http::assertSentCount(2);
    }
}
