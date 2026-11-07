<?php

namespace Tests\Feature\Konsol;

use App\Jobs\BildirimKuyrugu\BildirimReklamGonderIsi;
use App\Jobs\BildirimKuyrugu\CarkDuyuruGonderIsi;
use App\Jobs\BildirimKuyrugu\SmsTopluGonderIsi;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Isleyici;
use App\Services\BildirimKuyrugu\Kanal\FcmIstemcisi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Toplu outbox turleri: metin ve kosullar eskiyle ayni. FCM sahte, HTTP Http::fake, ag istegi yok. */
class TopluBildirimIsleyicileriTest extends TestCase
{
    private const SALON = 20;

    private const TABLOLAR = [
        'salonlar' => ['salon_adi', 'domain', 'app_bundle', 'firebase_profile', 'kara_liste_aktif', 'yeni_sms', 'whatsapp_aktif', 'whatsapp_durum', 'whatsapp_paylasilan_salon_id'],
        'users' => ['name', 'cep_telefon'],
        'musteri_portfoy' => ['user_id', 'salon_id', 'kara_liste', 'aktif'],
        'bildirim_kimlikleri' => ['user_id', 'isletme_yetkili_id', 'salon_id', 'bildirim_id', 'aktif', 'platform', 'app_bundle', 'gonderim_hatalari', 'son_kullanim_tarihi'],
        'bildirimler' => ['salon_id', 'user_id', 'personel_id', 'baslik', 'aciklama', 'url', 'img_src', 'tarih_saat', 'okundu', 'butonlar', 'olcum_id', 'ilac_id', 'adet_duzeni_id', 'randevu_id', 'tip', 'deep_link'],
        'bildirim_reklamlari' => ['salon_id', 'baslik', 'mesaj', 'gorsel', 'kanal_push'],
        'carkifelek_sistemi' => ['salon_id', 'aktifmi', 'gecerli_salonlar'],
        'randevular' => ['salon_id', 'user_id', 'tarih', 'saat', 'notlar', 'personel_notu'],
        'kampanya_yonetimi' => ['salon_id', 'paket_isim'],
        'salon_personelleri' => ['salon_id', 'yetkili_id'],
        'anket_sablonlari' => ['salon_id', 'ad', 'aciklama', 'sorular_json', 'otomatik_gonder', 'gonder_saat_sonra', 'aktif', 'varsayilan', 'odul_tipi', 'odul_kupon_deger', 'odul_kupon_indirim_tipi', 'odul_puan'],
        'anket_gonderimleri' => ['salon_id', 'sablon_id', 'randevu_id', 'arsiv_id', 'user_id', 'personel_id', 'token', 'ad_soyad', 'telefon', 'gonderim_kanali', 'gonderim_zamani', 'son_gecerlilik'],
        'randevu_hizmetler' => ['randevu_id', 'hizmet_id', 'personel_id', 'saat', 'sure_dk'],
        'hizmetler' => ['hizmet_adi'],
        'google_calendar_baglantilar' => ['yetkili_id', 'salon_id', 'calendar_id', 'access_token', 'refresh_token', 'expires_at', 'aktif', 'son_hata_zamani', 'son_hata_mesaji'],
        'google_calendar_event_eslemeleri' => ['baglanti_id', 'randevu_hizmet_id', 'google_event_id', 'son_sync_zamani'],
    ];

    private SahteFcm $fcm;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABLOLAR as $tablo => $kolonlar) {
            Schema::create($tablo, function (Blueprint $t) use ($kolonlar) {
                $t->id();
                foreach ($kolonlar as $kolon) {
                    $t->string($kolon)->nullable();
                }
                $t->timestamps();
            });
        }
        $this->fcm = new SahteFcm;
        $this->app->instance(FcmIstemcisi::class, $this->fcm);
        config(['queue.default' => 'sync', 'panel.eski_panel_url' => 'https://panel.test', 'app.url' => 'https://yeni-app.test']);
        DB::table('salonlar')->insert(['id' => self::SALON, 'salon_adi' => 'Orbey', 'domain' => null]);
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TABLOLAR) as $tablo) {
            Schema::dropIfExists($tablo);
        }
        parent::tearDown();
    }

    private function isle(string $sinif, array $payload, array $alan = []): void
    {
        $kayit = (object) ($alan + ['id' => 1, 'salon_id' => self::SALON, 'tur' => 'x', 'randevu_id' => null, 'yetkili_id' => null]);
        app($sinif)->isle($kayit, $payload);
    }

    private function musteriCihazi(int $userId, string $ad = 'Ayse Yilmaz'): void
    {
        DB::table('users')->insert(['id' => $userId, 'name' => $ad, 'cep_telefon' => '5321234567']);
        DB::table('bildirim_kimlikleri')->insert(['user_id' => $userId, 'bildirim_id' => 'tok'.$userId, 'aktif' => 1, 'platform' => 'android']);
    }

    // ── harita ──

    public function test_toplu_turlerin_isleyicileri_haritada_ve_sozlesmeye_uygun(): void
    {
        foreach (['bildirim_reklam_push', 'cark_duyuru', 'sms_toplu', 'kampanya_olustur', 'anket_link', 'anket_gonder', 'google_takvim', 'saat_boslugu_kampanya', 'musteri_resim_push'] as $tur) {
            $this->assertTrue(is_subclass_of(config('bildirim_kuyrugu.harita')[$tur], BildirimIsleyici::class), $tur);
        }
    }

    // ── bildirim_reklam_push ──

    public function test_reklam_yoksa_hata_vermez_ve_gondermez(): void
    {
        $this->isle(Isleyici\BildirimReklamPushIsleyici::class, ['reklam_id' => 999, 'user_ids' => [1]]);

        $this->assertSame([], $this->fcm->gelen);
    }

    public function test_reklam_push_eski_alanlarla_ve_eski_panel_gorseliyle_gider(): void
    {
        $this->musteriCihazi(7);
        DB::table('bildirim_reklamlari')->insert(['id' => 5, 'salon_id' => self::SALON, 'baslik' => 'Indirim', 'mesaj' => 'Gel', 'gorsel' => '/public/reklam_gorselleri/a.png', 'kanal_push' => 1]);

        $this->isle(Isleyici\BildirimReklamPushIsleyici::class, ['reklam_id' => 5, 'user_ids' => [7, 7]]);

        $this->assertCount(1, $this->fcm->gelen);
        $m = $this->fcm->gelen[0]['mesaj'];
        $this->assertSame(['Indirim', 'Gel', 'https://panel.test/public/reklam_gorselleri/a.png'], [$m['notification']['title'], $m['notification']['body'], $m['notification']['image']]);
        $this->assertSame('discount', $m['data']['type']);
        $this->assertSame('reklam_detay?reklam_id=5', $m['data']['deep_link']);
    }

    public function test_reklam_push_kanali_kapaliysa_gitmez(): void
    {
        $this->musteriCihazi(7);
        DB::table('bildirim_reklamlari')->insert(['id' => 5, 'salon_id' => self::SALON, 'baslik' => 'I', 'kanal_push' => 0]);

        $this->isle(Isleyici\BildirimReklamPushIsleyici::class, ['reklam_id' => 5, 'user_ids' => [7]]);

        $this->assertSame([], $this->fcm->gelen);
    }

    public function test_toplu_isler_sync_degilse_hatirlatmalar_kuyruguna_tek_denemeyle_gider(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        DB::table('bildirim_reklamlari')->insert(['id' => 5, 'salon_id' => self::SALON, 'baslik' => 'I', 'kanal_push' => 1]);

        $this->isle(Isleyici\BildirimReklamPushIsleyici::class, ['reklam_id' => 5, 'user_ids' => [7]]);
        $this->isle(Isleyici\CarkDuyuruIsleyici::class, ['baslik' => 'B', 'mesaj' => 'M']);
        $this->isle(Isleyici\SmsTopluIsleyici::class, ['telefonlar' => ['5321234567'], 'mesaj' => 'M']);

        foreach ([BildirimReklamGonderIsi::class, CarkDuyuruGonderIsi::class, SmsTopluGonderIsi::class] as $sinif) {
            Bus::assertDispatched($sinif, fn ($is) => $is->queue === 'v2_bildirim' && $is->tries === 1 && $is->timeout === 1700);
        }
        $this->assertSame([], $this->fcm->gelen);
    }

    // ── cark_duyuru ──

    public function test_cark_duyuru_ikon_kisisellestirme_ve_varsayilan_mesaj(): void
    {
        $this->musteriCihazi(7);
        DB::table('carkifelek_sistemi')->insert(['salon_id' => self::SALON, 'aktifmi' => 1]);
        DB::table('randevular')->insert(['salon_id' => self::SALON, 'user_id' => 7]);

        $this->isle(Isleyici\CarkDuyuruIsleyici::class, ['baslik' => '{ad} {salon_adi}', 'mesaj' => '']);

        $m = $this->fcm->gelen[0]['mesaj'];
        $this->assertSame('🎡 Ayse Orbey', $m['notification']['title']);
        $this->assertSame("Çark-ı Felek'i çevir, sürpriz ödülü kap! Bugün şansını dene 🎁", $m['notification']['body']);
        $this->assertSame(['wheel_chance', 'wheel?salon_id=20', '1', 'admin_duyuru'], [$m['data']['type'], $m['data']['deep_link'], $m['data']['popup'], $m['data']['kaynak']]);
    }

    public function test_cark_pasifse_duyuru_gitmez(): void
    {
        $this->musteriCihazi(7);
        DB::table('carkifelek_sistemi')->insert(['salon_id' => self::SALON, 'aktifmi' => 0]);
        DB::table('randevular')->insert(['salon_id' => self::SALON, 'user_id' => 7]);

        $this->isle(Isleyici\CarkDuyuruIsleyici::class, ['baslik' => 'B', 'mesaj' => 'M']);

        $this->assertSame([], $this->fcm->gelen);
    }

    // ── sms_toplu ──

    public function test_sms_toplu_alici_veya_mesaj_yoksa_eski_hata(): void
    {
        $this->expectExceptionObject(new RuntimeException('sms_toplu: alici/mesaj yok'));

        $this->isle(Isleyici\SmsTopluIsleyici::class, ['telefonlar' => [], 'mesaj' => 'M']);
    }

    public function test_sms_toplu_yeni_sms_salonda_voice_telekom_paketi(): void
    {
        DB::table('salonlar')->where('id', self::SALON)->update(['yeni_sms' => 1]);
        $sms = Mockery::mock(SmsKanali::class);
        $sms->shouldReceive('topluVoiceTelekom')->once()->with(['5321234567'], 'M', self::SALON, 'Toplu SMS')->andReturn('başarılı');
        $this->app->instance(SmsKanali::class, $sms);

        $this->isle(Isleyici\SmsTopluIsleyici::class, ['telefonlar' => ['5321234567', ''], 'mesaj' => 'M']);
    }

    public function test_sms_toplu_diger_salonda_eski_api_yolu_ve_hata_yutulur(): void
    {
        $sms = Mockery::mock(SmsKanali::class);
        $sms->shouldReceive('apiBildirimli')->once()->with([['to' => '5321234567', 'message' => 'M']], true, 4, false, self::SALON)->andThrow(new RuntimeException('saglayici'));
        $this->app->instance(SmsKanali::class, $sms);

        $this->isle(Isleyici\SmsTopluIsleyici::class, ['telefonlar' => ['5321234567'], 'mesaj' => 'M']);
    }

    // ── kampanya_olustur ──

    private function kampanyaKur(): void
    {
        DB::table('kampanya_yonetimi')->insert(['id' => 3, 'salon_id' => self::SALON, 'paket_isim' => 'Yaz']);
        DB::table('salon_personelleri')->insert(['id' => 8, 'salon_id' => self::SALON]);
        foreach (['a', 'b'] as $tok) {
            DB::table('bildirim_kimlikleri')->insert(['isletme_yetkili_id' => 8, 'salon_id' => self::SALON, 'bildirim_id' => $tok]);
        }
    }

    public function test_kampanya_yoksa_eski_hata(): void
    {
        $this->expectExceptionObject(new RuntimeException('kampanya_olustur: kampanya yok: 3'));

        $this->isle(Isleyici\KampanyaOlusturIsleyici::class, ['kampanya_id' => 3]);
    }

    public function test_kampanya_push_eski_data_mesaji_varsayilan_proje_ve_bildirim_satiri(): void
    {
        $this->kampanyaKur();

        $this->isle(Isleyici\KampanyaOlusturIsleyici::class, ['kampanya_id' => 3]);

        $this->assertCount(2, $this->fcm->gelen);
        $g = $this->fcm->gelen[0];
        $this->assertSame(config('firebase_projects.default'), $g['dosya']);
        $this->assertArrayNotHasKey('notification', $g['mesaj']);
        $this->assertSame('Yeni Reklam Kampanyası Oluşturma', $g['mesaj']['data']['title']);
        $this->assertSame('Sisteme Yaz ile ilgili reklam kaydını başarıyla olşturdunuz.', $g['mesaj']['data']['body']);
        $this->assertSame('reklam', $g['mesaj']['apns']['payload']['aps']['category']);
        $satir = DB::table('bildirimler')->first();
        $this->assertSame(['kampanya', '/public/yeni_panel/vendors/images/eczane24-icon.jpg', '[]', null], [$satir->url, $satir->img_src, $satir->butonlar, $satir->user_id]);
        $this->assertSame(2, DB::table('bildirimler')->count());
    }

    public function test_kampanya_gecersiz_token_silinir_diger_cihaza_devam_edilir(): void
    {
        $this->kampanyaKur();
        $this->fcm->hatalar = ['a' => new RuntimeException('404 UNREGISTERED')];

        $this->isle(Isleyici\KampanyaOlusturIsleyici::class, ['kampanya_id' => 3]);

        $this->assertSame(['b'], DB::table('bildirim_kimlikleri')->pluck('bildirim_id')->all());
        $this->assertSame(1, DB::table('bildirimler')->count());
    }

    public function test_kampanya_diger_fcm_hatasi_kalan_cihazlari_atlar_kayit_hatasiz_biter(): void
    {
        $this->kampanyaKur();
        $this->fcm->hatalar = ['a' => new RuntimeException('500 ic hata')];

        $this->isle(Isleyici\KampanyaOlusturIsleyici::class, ['kampanya_id' => 3]);

        $this->assertSame([], $this->fcm->gelen);
        $this->assertSame(2, DB::table('bildirim_kimlikleri')->count());
    }

    // ── anket_link ──

    private function anketKur(array $sablon = []): int
    {
        DB::table('anket_sablonlari')->insert(['id' => 2, 'salon_id' => self::SALON, 'aktif' => 1] + $sablon);

        return (int) DB::table('anket_gonderimleri')->insertGetId(['salon_id' => self::SALON, 'sablon_id' => 2, 'token' => 'ABC12345', 'ad_soyad' => 'Ali Veli', 'telefon' => '5321234567', 'gonderim_kanali' => 'manuel']);
    }

    /** @return object{sms:\Mockery\MockInterface, whatsapp:\Mockery\MockInterface, mesajlar:\ArrayObject} */
    private function anketKanallari(bool $bagli, array $waSonuc = []): object
    {
        $mesajlar = new \ArrayObject;
        $sms = Mockery::mock(SmsKanali::class);
        $sms->shouldReceive('gonder')->andReturnUsing(function ($salonId, $liste) use ($mesajlar) {
            $mesajlar['sms'] = $liste;

            return null;
        });
        $wa = Mockery::mock(WhatsappKanali::class);
        $wa->shouldReceive('salon')->andReturnUsing(fn ($id) => DB::table('salonlar')->where('id', $id)->first());
        $wa->shouldReceive('oturumSalonu')->andReturnArg(0);
        $wa->shouldReceive('bagliMi')->andReturn($bagli);
        $wa->shouldReceive('gonder')->andReturnUsing(function (...$a) use ($mesajlar, $waSonuc) {
            $mesajlar['wa'] = $a;

            return $waSonuc;
        });
        $this->app->instance(SmsKanali::class, $sms);
        $this->app->instance(WhatsappKanali::class, $wa);

        return (object) ['sms' => $sms, 'whatsapp' => $wa, 'mesajlar' => $mesajlar];
    }

    public function test_anket_gonderimi_yoksa_hata_vermez(): void
    {
        $k = $this->anketKanallari(false);

        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => 99]);

        $this->assertArrayNotHasKey('sms', $k->mesajlar);
    }

    public function test_anket_linki_salon_domain_host_u_ile_ve_eski_metinle_sms_e_gider(): void
    {
        DB::table('salonlar')->where('id', self::SALON)->update(['domain' => 'orbey.test']);
        $id = $this->anketKur();
        $k = $this->anketKanallari(false);

        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => $id]);

        $beklenen = "Merhaba Sn. Ali,\n\nOrbey'da yaşadığınız deneyim bizim için çok değerli. Hizmet kalitemizi geliştirebilmemiz adına görüşlerinizi birkaç dakikanızı ayırarak bizimle paylaşabilir misiniz?\n\n"
            ."Anketi doldurmak için:\nhttps://orbey.test/anket/ABC12345\n\nDeğerli geri bildiriminiz için şimdiden teşekkür eder, sizi yeniden ağırlamaktan mutluluk duyarız.";
        $this->assertSame([['to' => '5321234567', 'message' => $beklenen]], $k->mesajlar['sms']);
    }

    public function test_anket_linki_domain_yoksa_eski_panel_host_u_yeni_app_degil(): void
    {
        $id = $this->anketKur();
        $k = $this->anketKanallari(false);

        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => $id]);

        $mesaj = $k->mesajlar['sms'][0]['message'];
        $this->assertStringContainsString('https://panel.test/anket/ABC12345', $mesaj);
        $this->assertStringNotContainsString('yeni-app.test', $mesaj);
    }

    public function test_anket_kupon_ve_puan_odullu_davet_metni(): void
    {
        $id = $this->anketKur(['odul_tipi' => 'kupon', 'odul_kupon_deger' => '50.00', 'odul_kupon_indirim_tipi' => 'tutar']);
        $k = $this->anketKanallari(false);

        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => $id]);
        $this->assertStringStartsWith("Merhaba Sn. Ali 🎁\n\nOrbey deneyiminizi değerlendirin, size özel 50 ₺ indirim kuponu hediyemiz olsun!\n\nAnketi doldurmak (30 sn):\n", $k->mesajlar['sms'][0]['message']);
        $this->assertStringEndsWith("\n\nGörüşleriniz bizim için çok değerli, teşekkür ederiz.", $k->mesajlar['sms'][0]['message']);

        DB::table('anket_sablonlari')->where('id', 2)->update(['odul_kupon_indirim_tipi' => 'yuzde', 'odul_kupon_deger' => '15.5']);
        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => $id]);
        $this->assertStringContainsString('size özel 15% indirim kuponu hediyemiz', $k->mesajlar['sms'][0]['message']);

        DB::table('anket_sablonlari')->where('id', 2)->update(['odul_tipi' => 'puan', 'odul_puan' => '100']);
        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => $id]);
        $this->assertStringContainsString('size özel 100 puan hediyemiz', $k->mesajlar['sms'][0]['message']);
    }

    public function test_anket_whatsapp_basarili_ise_kanal_yazilir_sms_gitmez(): void
    {
        $id = $this->anketKur();
        $k = $this->anketKanallari(true, ['ok' => true, 'logId' => 1, 'provider' => 'baileys']);

        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => $id]);

        $this->assertSame('anket', $k->mesajlar['wa'][7]);
        $this->assertArrayNotHasKey('sms', $k->mesajlar);
        $this->assertSame('whatsapp', DB::table('anket_gonderimleri')->where('id', $id)->value('gonderim_kanali'));
    }

    public function test_anket_whatsapp_basarisizsa_sms_e_duser_kanal_degismez(): void
    {
        $id = $this->anketKur();
        $k = $this->anketKanallari(true, ['ok' => false, 'error' => 'kontor-yetersiz']);

        $this->isle(Isleyici\AnketLinkIsleyici::class, ['gonderim_id' => $id]);

        $this->assertArrayHasKey('sms', $k->mesajlar);
        $this->assertSame('manuel', DB::table('anket_gonderimleri')->where('id', $id)->value('gonderim_kanali'));
    }

    // ── anket_gonder ──

    public function test_anket_gonder_telefon_yoksa_eski_hata(): void
    {
        DB::table('users')->insert(['id' => 7, 'name' => 'Ali']);
        $this->expectExceptionObject(new RuntimeException('anket_gonder: musteri/telefon yok: 7'));

        $this->isle(Isleyici\AnketGonderIsleyici::class, ['user_id' => 7]);
    }

    public function test_anket_gonder_eski_varsayilan_sablonu_ve_kaydi_olusturup_yollar(): void
    {
        DB::table('users')->insert(['id' => 7, 'name' => 'Ali Veli', 'cep_telefon' => '5321234567']);
        $k = $this->anketKanallari(false);

        $this->isle(Isleyici\AnketGonderIsleyici::class, ['user_id' => 7, 'personel_id' => 8, 'kanal' => 'cagri']);

        $sablon = DB::table('anket_sablonlari')->first();
        $this->assertSame(['Varsayilan Memnuniyet Anketi', '24', '1'], [$sablon->ad, (string) $sablon->gonder_saat_sonra, (string) $sablon->varsayilan]);
        $g = DB::table('anket_gonderimleri')->first();
        $this->assertSame(['cagri', '8', 'Ali Veli', 8], [$g->gonderim_kanali, (string) $g->personel_id, $g->ad_soyad, strlen($g->token)]);
        $this->assertStringContainsString('/anket/'.$g->token, $k->mesajlar['sms'][0]['message']);
    }

    // ── google_takvim ──

    private function googleKur(array $baglanti = []): void
    {
        DB::table('users')->insert(['id' => 7, 'name' => 'Ayse']);
        DB::table('hizmetler')->insert(['id' => 4, 'hizmet_adi' => 'Sac']);
        DB::table('randevular')->insert(['id' => 9, 'salon_id' => self::SALON, 'user_id' => 7, 'tarih' => '2026-12-01', 'saat' => '10:00:00', 'notlar' => 'Not', 'personel_notu' => null]);
        DB::table('randevu_hizmetler')->insert(['id' => 11, 'randevu_id' => 9, 'hizmet_id' => 4, 'personel_id' => 8, 'saat' => '10:30:00', 'sure_dk' => 45]);
        DB::table('google_calendar_baglantilar')->insert($baglanti + [
            'id' => 1, 'yetkili_id' => 50, 'salon_id' => self::SALON, 'calendar_id' => 'primary', 'access_token' => 'tok', 'refresh_token' => 'yenile',
            'expires_at' => now()->addHour()->toDateTimeString(), 'aktif' => 1,
        ]);
    }

    public function test_google_yeni_etkinlik_eski_alanlarla_yazilir_ve_eslenir(): void
    {
        $this->googleKur();
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ev1'])]);

        $this->isle(Isleyici\GoogleTakvimIsleyici::class, ['sync' => [11]]);

        Http::assertSent(function (Request $r) {
            $g = json_decode($r->body(), true);

            return $r->method() === 'POST' && $r->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events'
                && $r->hasHeader('Authorization', 'Bearer tok')
                && $g['summary'] === 'Ayse — Sac' && $g['description'] === "Musteri notu: Not\nRandevu #9"
                && $g['start'] === ['dateTime' => '2026-12-01T10:30:00+03:00', 'timeZone' => 'Europe/Istanbul']
                && $g['end']['dateTime'] === '2026-12-01T11:15:00+03:00'
                && $g['extendedProperties']['private'] === ['randevumcepte_randevu_id' => '9', 'randevumcepte_randevu_hizmet_id' => '11']
                && $g['source'] === ['title' => 'Randevumcepte', 'url' => 'https://panel.test'];
        });
        $this->assertSame('ev1', DB::table('google_calendar_event_eslemeleri')->where('randevu_hizmet_id', 11)->value('google_event_id'));
    }

    public function test_google_eslesen_etkinlik_patch_ile_guncellenir(): void
    {
        $this->googleKur();
        DB::table('google_calendar_event_eslemeleri')->insert(['baglanti_id' => 1, 'randevu_hizmet_id' => 11, 'google_event_id' => 'ev1']);
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'ev1'])]);

        $this->isle(Isleyici\GoogleTakvimIsleyici::class, ['sync' => [11]]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/events/ev1'));
        $this->assertSame(1, DB::table('google_calendar_event_eslemeleri')->count());
    }

    public function test_google_baska_personelin_baglantisina_yazmaz_personelsiz_kalemi_atlar(): void
    {
        $this->googleKur();
        DB::table('salon_personelleri')->insert(['id' => 99, 'salon_id' => self::SALON, 'yetkili_id' => 50]);
        DB::table('randevu_hizmetler')->insert(['id' => 12, 'randevu_id' => 9, 'personel_id' => null]);
        Http::fake();

        $this->isle(Isleyici\GoogleTakvimIsleyici::class, ['sync' => [11, 12, 999]]);

        Http::assertNothingSent();
    }

    public function test_google_suresi_dolmus_belirteci_tazeler(): void
    {
        $this->googleKur(['expires_at' => now()->subHour()->toDateTimeString()]);
        config(['bildirim_kuyrugu.google_takvim' => ['client_id' => 'cid', 'client_secret' => 'gizli']]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'yeni', 'expires_in' => 3600]),
            'www.googleapis.com/*'    => Http::response(['id' => 'ev1']),
        ]);

        $this->isle(Isleyici\GoogleTakvimIsleyici::class, ['sync' => [11]]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'yenile' && $r['client_id'] === 'cid');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'calendar') && $r->hasHeader('Authorization', 'Bearer yeni'));
        $this->assertSame('yeni', DB::table('google_calendar_baglantilar')->value('access_token'));
    }

    public function test_google_api_hatasi_baglantiya_yazilir_kayit_hatasiz_biter(): void
    {
        $this->googleKur();
        Http::fake(['www.googleapis.com/*' => Http::response('yasak', 403)]);

        $this->isle(Isleyici\GoogleTakvimIsleyici::class, ['sync' => [11]]);

        $this->assertSame('HTTP 403 yasak', DB::table('google_calendar_baglantilar')->value('son_hata_mesaji'));
        $this->assertSame(0, DB::table('google_calendar_event_eslemeleri')->count());
    }

    public function test_google_silme_404_basarili_silinemese_de_esleme_kalkar_randevu_sart_degil(): void
    {
        $this->googleKur();
        DB::table('randevular')->delete();
        DB::table('google_calendar_event_eslemeleri')->insert([
            ['baglanti_id' => 1, 'randevu_hizmet_id' => 11, 'google_event_id' => 'ev1'],
            ['baglanti_id' => 1, 'randevu_hizmet_id' => 11, 'google_event_id' => 'ev2'],
        ]);
        Http::fake(['*/events/ev1' => Http::response('', 404), '*/events/ev2' => Http::response('ariza', 500)]);

        $this->isle(Isleyici\GoogleTakvimIsleyici::class, ['sil' => [11]]);

        $this->assertSame(0, DB::table('google_calendar_event_eslemeleri')->count());
        $this->assertSame('HTTP 500 ariza', DB::table('google_calendar_baglantilar')->value('son_hata_mesaji'));
    }

    // ── saat_boslugu_kampanya ──

    public function test_saat_boslugu_varsayilan_eski_isleyici_gibi_hataya_duser(): void
    {
        config(['bildirim_kuyrugu.saat_boslugu_push_aktif' => false]);
        Cache::forget('dashKarsVer_20');
        $this->musteriCihazi(7);

        try {
            $this->isle(Isleyici\SaatBosluguKampanyaIsleyici::class, ['kampanya_id' => 1, 'baslik' => 'B', 'mesaj' => 'M', 'user_ids' => [7]]);
            $this->fail('istisna bekleniyordu');
        } catch (RuntimeException $e) {
            $this->assertSame('Randevu bulunamadi: ', $e->getMessage());
        }
        $this->assertSame([], $this->fcm->gelen);
        $this->assertNull(Cache::get('dashKarsVer_20'));
    }

    public function test_saat_boslugu_acikken_v1_pushu_ve_onbellek_damgasi(): void
    {
        config(['bildirim_kuyrugu.saat_boslugu_push_aktif' => true]);
        Cache::forever('dashKarsVer_20', 4);
        $this->musteriCihazi(7);

        $this->isle(Isleyici\SaatBosluguKampanyaIsleyici::class, ['kampanya_id' => 1, 'baslik' => 'B', 'mesaj' => 'M', 'user_ids' => [7]]);

        $this->assertSame(5, Cache::get('dashKarsVer_20'));
        $m = $this->fcm->gelen[0]['mesaj'];
        $this->assertSame(['B', 'M'], [$m['notification']['title'], $m['notification']['body']]);
        $this->assertSame(['discount', '1', 'gap_kampanya', '1'], [$m['data']['type'], $m['data']['popup'], $m['data']['kaynak'], $m['data']['kampanya_id']]);
    }

    public function test_saat_boslugu_iptal_satiri_yalniz_onbellek_dusurur(): void
    {
        config(['bildirim_kuyrugu.saat_boslugu_push_aktif' => true]);
        Cache::forget('dashKarsVer_20');

        $this->isle(Isleyici\SaatBosluguKampanyaIsleyici::class, ['kampanya_id' => 1, 'user_ids' => []]);

        $this->assertSame(1, Cache::get('dashKarsVer_20'));
        $this->assertSame([], $this->fcm->gelen);
    }

    // ── musteri_resim_push ──

    public function test_musteri_resmi_personele_v1_metniyle_gider(): void
    {
        DB::table('users')->insert(['id' => 7, 'name' => 'Ayse']);
        DB::table('salon_personelleri')->insert(['id' => 8, 'salon_id' => self::SALON]);
        DB::table('bildirim_kimlikleri')->insert(['isletme_yetkili_id' => 8, 'bildirim_id' => 'tok', 'aktif' => 1, 'platform' => 'android']);

        $this->isle(Isleyici\MusteriResimPushIsleyici::class, ['user_id' => 7, 'yol' => 'public/musteri_gorselleri/a.jpg']);

        $m = $this->fcm->gelen[0]['mesaj'];
        $this->assertSame(['Yeni müşteri fotoğrafı', 'Ayse bir fotoğraf ekledi', 'https://panel.test/public/musteri_gorselleri/a.jpg'], [$m['notification']['title'], $m['notification']['body'], $m['notification']['image']]);
        $this->assertSame(['musteri_resim_yuklendi', 'customer_detail?user_id=7', '7'], [$m['data']['type'], $m['data']['deep_link'], $m['data']['user_id']]);
    }
}

class SahteFcm extends FcmIstemcisi
{
    public array $gelen = [];

    /** @var array<string, \Throwable> token → firlatilacak hata */
    public array $hatalar = [];

    public function gonder(string $dosya, array $mesaj): void
    {
        if (isset($this->hatalar[$mesaj['token']])) {
            throw $this->hatalar[$mesaj['token']];
        }
        $this->gelen[] = ['dosya' => $dosya, 'mesaj' => $mesaj];
    }
}
