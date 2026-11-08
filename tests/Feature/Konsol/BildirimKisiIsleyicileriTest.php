<?php

namespace Tests\Feature\Konsol;

use App\Services\BildirimKuyrugu\BildirimYonlendirici;
use App\Services\BildirimKuyrugu\Kanal\FcmIstemcisi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Services\Oturum\PlatformSms;
use App\Services\Whatsapp\Kopru\KopruCevabi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Kisi turleri: kanallar sahte, ag istegi yok. Satirlar yonlendirici uzerinden config haritasiyla islenir. */
class BildirimKisiIsleyicileriTest extends TestCase
{
    private const SALON = 20;

    private const TEL = '5321234567';

    private const TABLOLAR = [
        'salonlar', 'users', 'salon_personelleri', 'isletmeyetkilileri', 'oauth_access_tokens', 'salon_sms_ayarlari',
        'musteri_portfoy', 'salon_aktivite_log', 'bildirim_kimlikleri', 'bildirimler',
    ];

    private array $push = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tablolariKur();
        config([
            'panel.eski_panel_url'           => 'https://panel.test',
            'firebase_projects.default'      => 'varsayilan.json',
            'oturum.korunan_hesaplar.isletme' => [3],
        ]);
        $this->push = [];
        $this->mock(FcmIstemcisi::class, function ($m) {
            $m->shouldReceive('gonder')->andReturnUsing(function ($dosya, $mesaj) {
                $this->push[] = $mesaj;
            });
        });
        DB::table('salonlar')->insert(['id' => self::SALON, 'salon_adi' => 'Test Salon', 'telefon_1' => '2321112233']);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLOLAR as $tablo) {
            Schema::dropIfExists($tablo);
        }
        parent::tearDown();
    }

    private function tablolariKur(): void
    {
        $kolonlar = [
            'salonlar' => ['salon_adi', 'telefon_1', 'uygulamalar_kisa_link', 'app_bundle', 'firebase_profile', 'whatsapp_aktif', 'whatsapp_durum', 'whatsapp_saglayici',
                'whatsapp_paylasilan_salon_id', 'cloud_api_token', 'cloud_api_phone_number_id'],
            'users'                => ['name', 'cep_telefon', 'cinsiyet', 'password'],
            'isletmeyetkilileri'   => ['name', 'gsm1', 'password', 'oturum_iptal_tarihi', 'created_at', 'updated_at'],
            'salon_personelleri'   => ['salon_id', 'yetkili_id', 'personel_adi', 'aktif', 'sifre', 'created_at', 'updated_at'],
            'salon_sms_ayarlari'   => ['salon_id', 'ayar_id', 'musteri'],
            'musteri_portfoy'      => ['user_id', 'salon_id', 'kara_liste'],
            'salon_aktivite_log'   => ['salon_id', 'user_id', 'user_type', 'user_name', 'user_rol', 'action', 'target_type', 'target_id', 'target_label', 'aciklama', 'meta', 'ip', 'user_agent', 'created_at', 'updated_at'],
            'bildirim_kimlikleri'  => ['user_id', 'isletme_yetkili_id', 'bildirim_id', 'aktif', 'platform', 'app_bundle', 'gonderim_hatalari', 'son_kullanim_tarihi', 'updated_at'],
            'bildirimler'          => ['salon_id', 'user_id', 'personel_id', 'aciklama', 'baslik', 'tip', 'extra_data', 'created_at', 'updated_at'],
        ];
        foreach ($kolonlar as $tablo => $alanlar) {
            Schema::create($tablo, function (Blueprint $t) use ($alanlar) {
                $t->id();
                foreach ($alanlar as $alan) {
                    $t->string($alan)->nullable();
                }
            });
        }
        Schema::create('oauth_access_tokens', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->integer('user_id');
            $t->string('name')->nullable();
            $t->integer('revoked')->default(0);
            $t->timestamp('updated_at')->nullable();
        });
    }

    private function isle(string $tur, array $payload): void
    {
        app(BildirimYonlendirici::class)->yonlendir((object) [
            'id' => 1, 'salon_id' => self::SALON, 'tur' => $tur, 'randevu_id' => null, 'yetkili_id' => null,
            'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, 'durum' => 3, 'deneme' => 0,
        ]);
    }

    private function hataBekle(string $tur, array $payload, string $metin): void
    {
        try {
            $this->isle($tur, $payload);
            $this->fail('Hata bekleniyordu: '.$metin);
        } catch (RuntimeException $e) {
            $this->assertSame($metin, $e->getMessage());
        }
    }

    private function sms(): \Mockery\MockInterface
    {
        return $this->mock(SmsKanali::class);
    }

    private function whatsapp(): \Mockery\MockInterface
    {
        return $this->partialMock(WhatsappKanali::class);
    }

    private function waBagla(int $salonId = self::SALON): void
    {
        DB::table('salonlar')->where('id', $salonId)->update(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);
    }

    private function yetkili(int $id = 7): int
    {
        return (int) DB::table('isletmeyetkilileri')->insertGetId(['id' => $id, 'name' => 'Ali', 'gsm1' => self::TEL, 'password' => 'eski']);
    }

    private function personel(int $yetkiliId = 7, array $alan = []): int
    {
        return (int) DB::table('salon_personelleri')->insertGetId($alan + ['salon_id' => self::SALON, 'yetkili_id' => $yetkiliId, 'personel_adi' => 'Ali P', 'aktif' => 0]);
    }

    private function musteri(array $alan = []): int
    {
        return (int) DB::table('users')->insertGetId($alan + ['name' => 'Ayşe Kaya', 'cep_telefon' => self::TEL, 'password' => 'eski']);
    }

    private function cihaz(array $alan): void
    {
        static $sira = 0;
        DB::table('bildirim_kimlikleri')->insert($alan + ['bildirim_id' => 'token-'.(++$sira), 'aktif' => 1, 'platform' => 'android']);
    }

    private function token(string $id, int $userId, ?string $ad = 'appToken'): void
    {
        DB::table('oauth_access_tokens')->insert(['id' => $id, 'user_id' => $userId, 'name' => $ad, 'revoked' => 0]);
    }

    private function iptalMi(string $id): bool
    {
        return (int) DB::table('oauth_access_tokens')->where('id', $id)->value('revoked') === 1;
    }

    // ---- personel_sifre

    public function test_personel_sifre_personel_ve_hesap_yoksa_eski_hata(): void
    {
        $this->hataBekle('personel_sifre', ['personel_id' => 9], 'Personel bulunamadi: 9');
        $this->hataBekle('personel_sifre', [], 'Personel bulunamadi: -');

        $id = (int) DB::table('salon_personelleri')->insertGetId(['salon_id' => self::SALON, 'yetkili_id' => null]);
        $this->hataBekle('personel_sifre', ['personel_id' => $id], 'Personelin giris hesabi yok: '.$id);
    }

    public function test_personel_sifre_yetkili_satiri_yoksa_sessiz_doner(): void
    {
        $sms = $this->sms();
        $sms->shouldNotReceive('gonder2');

        $this->isle('personel_sifre', ['personel_id' => $this->personel(99)]);

        $this->assertSame(0, DB::table('salon_aktivite_log')->count());
    }

    public function test_personel_sifre_korunan_hesapta_sabit_sifre_ve_mesaj_yok(): void
    {
        $sms = $this->sms();
        $sms->shouldNotReceive('gonder2');
        $wa = $this->whatsapp();
        $wa->shouldNotReceive('acilGonder');
        $this->waBagla();
        $this->yetkili(3);
        $id = $this->personel(3);

        $this->isle('personel_sifre', ['personel_id' => $id]);

        $this->assertTrue(Hash::check('1234', DB::table('isletmeyetkilileri')->where('id', 3)->value('password')));
        $this->assertNull(DB::table('salon_personelleri')->where('id', $id)->value('sifre'));
        $this->assertSame(0, DB::table('salon_aktivite_log')->count());
    }

    public function test_personel_sifre_whatsapp_basariliysa_sms_gitmez_ve_metin_eskiyle_ayni(): void
    {
        $this->waBagla();
        $this->yetkili();
        $id = $this->personel();
        $giden = null;
        $wa = $this->whatsapp();
        $wa->shouldReceive('acilGonder')->once()->with(Mockery::any(), self::TEL, Mockery::any(), 7, 'sifre_sifirlama')
            ->andReturnUsing(function ($salon, $tel, $metin) use (&$giden) {
                $giden = $metin;

                return ['ok' => true, 'logId' => 1];
            });
        $sms = $this->sms();
        $sms->shouldNotReceive('gonder2');

        $this->isle('personel_sifre', ['personel_id' => $id]);

        $this->assertMatchesRegularExpression('/^Sayın Ali\. Randevumcepte yeni şifreniz : ([A-HJ-Z0-9]{6}) olarak güncellenmiştir\.$/u', $giden);
        preg_match('/: ([A-HJ-Z0-9]{6}) /u', $giden, $e);
        $sifre = $e[1];
        $this->assertNull(DB::table('salon_personelleri')->where('id', $id)->value('sifre'), 'acik sifre saklanmaz');
        $this->assertTrue(Hash::check($sifre, DB::table('isletmeyetkilileri')->where('id', 7)->value('password')));
    }

    public function test_personel_sifre_whatsapp_kapaliysa_sms_2_yoluyla_yeni_hesap_metni_ve_aktivite_mobil(): void
    {
        $this->yetkili();
        $id = $this->personel();
        $giden = null;
        $sms = $this->sms();
        $sms->shouldReceive('gonder2')->once()->with(Mockery::any(), false, 1, false, Mockery::any(), true)
            ->andReturnUsing(function ($mesajlar) use (&$giden) {
                $giden = $mesajlar;

                return '';
            });

        $this->isle('personel_sifre', ['personel_id' => $id, 'yeni_hesap' => true]);

        preg_match('/şifreniz : ([A-HJ-Z0-9]{6})$/u', $giden[0]['message'], $e);
        $sifre = $e[1] ?? '';
        $this->assertSame([['to' => self::TEL, 'message' => 'Sayın Ali. Randevu sistemi şifreniz : '.$sifre]], $giden);
        $this->assertTrue(Hash::check($sifre, DB::table('isletmeyetkilileri')->where('id', 7)->value('password')));

        $log = DB::table('salon_aktivite_log')->first();
        $this->assertSame(['sistem', 'Mobil', 'Mobil', 'personel_sifre_gonder', 'personel', 'Personele yeni sifre SMS gonderildi', '{"kaynak":"mobil"}'],
            [$log->user_type, $log->user_name, $log->user_rol, $log->action, $log->target_type, $log->aciklama, $log->meta]);
    }

    public function test_personel_sifre_whatsapp_hatasinda_sms_e_duser(): void
    {
        $this->waBagla();
        $this->yetkili();
        $wa = $this->whatsapp();
        $wa->shouldReceive('acilGonder')->once()->andReturn(['ok' => false, 'error' => 'daily-cap-reached']);
        $sms = $this->sms();
        $sms->shouldReceive('gonder2')->once()->andReturn('');

        $this->isle('personel_sifre', ['personel_id' => $this->personel()]);
    }

    // ---- yetki_degisti

    public function test_yetki_degisti_force_logout_push_metni(): void
    {
        $id = $this->personel();
        $this->cihaz(['isletme_yetkili_id' => $id]);

        $this->isle('yetki_degisti', ['personel_id' => $id]);

        $this->assertCount(1, $this->push);
        $this->assertSame('Yetkileriniz Güncellendi', $this->push[0]['notification']['title']);
        $this->assertSame('Yöneticiniz yetkilerinizi güncelledi. Devam etmek için tekrar giriş yapmanız gerekiyor.', $this->push[0]['notification']['body']);
        $this->assertSame('yetki_degisti', $this->push[0]['data']['type']);
        $this->assertSame('1', $this->push[0]['data']['force_logout']);
    }

    public function test_yetki_degisti_personel_yoksa_hata(): void
    {
        $this->hataBekle('yetki_degisti', ['personel_id' => 5], 'Personel bulunamadi: 5');
    }

    // ---- personel_pasif

    public function test_personel_pasif_baska_aktif_satir_yoksa_oturum_kapanir(): void
    {
        $this->yetkili();
        $id = $this->personel();
        $this->cihaz(['isletme_yetkili_id' => $id]);
        $this->token('t1', 7);

        $this->isle('personel_pasif', ['personel_id' => $id]);

        $this->assertTrue($this->iptalMi('t1'));
        $this->assertNotNull(DB::table('isletmeyetkilileri')->where('id', 7)->value('oturum_iptal_tarihi'));
        $this->assertSame('Hesabınız pasife alındı', $this->push[0]['notification']['title']);
        $this->assertSame('Yöneticiniz hesabınızı pasife aldı. Oturumunuz sonlandırıldı, tekrar giriş yapmanız gerekiyor.', $this->push[0]['notification']['body']);
        $this->assertSame('1', $this->push[0]['data']['force_logout']);
    }

    public function test_personel_pasif_ayni_markada_aktif_satir_varsa_dokunulmaz_baska_markada_ise_kapanir(): void
    {
        DB::table('salonlar')->where('id', self::SALON)->update(['app_bundle' => 'marka.a']);
        DB::table('salonlar')->insert(['id' => 21, 'app_bundle' => 'marka.a']);
        DB::table('salonlar')->insert(['id' => 22, 'app_bundle' => 'marka.b']);
        $this->yetkili();
        $id = $this->personel();
        $this->token('t1', 7);
        $baska = $this->personel(7, ['salon_id' => 22, 'aktif' => 1]);

        $this->isle('personel_pasif', ['personel_id' => $id]);
        $this->assertTrue($this->iptalMi('t1'), 'Baska markadaki aktiflik bu markanin oturumunu tutmaz');

        DB::table('salon_personelleri')->where('id', $baska)->update(['salon_id' => 21]);
        DB::table('oauth_access_tokens')->update(['revoked' => 0]);
        $this->isle('personel_pasif', ['personel_id' => $id]);
        $this->assertFalse($this->iptalMi('t1'));
    }

    public function test_personel_pasif_korunan_hesap_sonlandirilmaz(): void
    {
        $this->yetkili(3);
        $id = $this->personel(3);
        $this->cihaz(['isletme_yetkili_id' => $id]);
        $this->token('t1', 3);

        $this->isle('personel_pasif', ['personel_id' => $id]);

        $this->assertFalse($this->iptalMi('t1'));
        $this->assertSame([], $this->push);
    }

    // ---- sifre_degisti

    public function test_sifre_degisti_haric_token_korunur_digerleri_iptal_tum_cihazlara_push(): void
    {
        $this->yetkili();
        $this->cihaz(['isletme_yetkili_id' => $this->personel()]);
        $this->cihaz(['isletme_yetkili_id' => $this->personel(7, ['salon_id' => self::SALON, 'aktif' => 1])]);
        $this->token('kalan', 7);
        $this->token('giden', 7);
        $this->token('sistem', 7, 'sistemYonetim');

        $this->isle('sifre_degisti', ['yetkili_id' => 7, 'haric_token' => 'kalan']);

        $this->assertFalse($this->iptalMi('kalan'));
        $this->assertTrue($this->iptalMi('giden'));
        $this->assertFalse($this->iptalMi('sistem'));
        $this->assertNotNull(DB::table('isletmeyetkilileri')->where('id', 7)->value('oturum_iptal_tarihi'));
        $this->assertCount(2, $this->push);
        $this->assertSame('Şifreniz değiştirildi', $this->push[0]['notification']['title']);
        $this->assertSame('Hesabınızın şifresi değiştirildi. Lütfen tekrar giriş yapınız.', $this->push[0]['notification']['body']);
        $this->assertSame('sifre_degisti', $this->push[0]['data']['type']);
    }

    public function test_sifre_degisti_yetkili_yoksa_hata(): void
    {
        $this->hataBekle('sifre_degisti', ['yetkili_id' => 44], 'Yetkili bulunamadi: 44');
        $this->hataBekle('sifre_degisti', [], 'Yetkili bulunamadi: -');
    }

    // ---- musteri_kayit

    public function test_musteri_kayit_onbellek_cinsiyet_sifre_ve_metin(): void
    {
        Cache::put('salon_hatirlatma.'.self::SALON, 'x', 60);
        DB::table('salon_sms_ayarlari')->insert(['salon_id' => self::SALON, 'ayar_id' => 4, 'musteri' => 1]);
        DB::table('salonlar')->where('id', self::SALON)->update(['uygulamalar_kisa_link' => 'https://k.test/a']);
        $id = $this->musteri();
        $giden = null;
        $sms = $this->sms();
        $sms->shouldReceive('panelBildirimli')->once()->with(Mockery::any(), false, 1, false, self::SALON)
            ->andReturnUsing(function ($mesajlar) use (&$giden) {
                $giden = $mesajlar[0];

                return null;
            });

        $this->isle('musteri_kayit', ['user_id' => $id, 'yeni_hesap' => true, 'cinsiyet_tahmin' => true]);

        $this->assertFalse(Cache::has('salon_hatirlatma.'.self::SALON));
        $this->assertSame('0', (string) DB::table('users')->where('id', $id)->value('cinsiyet'));
        $this->assertMatchesRegularExpression('/ Şifreniz:([A-Za-z0-9]{5}) Uygulamamızı/u', $giden['message']);
        preg_match('/ Şifreniz:([A-Za-z0-9]{5}) /u', $giden['message'], $e);
        $this->assertTrue(Hash::check($e[1], DB::table('users')->where('id', $id)->value('password')));
        $this->assertSame('Sayın Ayşe Kaya. Test Salon tarafından müşteri kaydınız oluşturulmuştur. Sistem kullanıcı adınız:'.self::TEL.' Şifreniz:'.$e[1]
            .' Uygulamamızı indirmek için linke tıklayın. https://k.test/a', $giden['message']);
        $this->assertSame(self::TEL, $giden['to']);
        $this->assertArrayNotHasKey('user_id', $giden);
    }

    public function test_musteri_kayit_ayar_kapaliysa_sifre_yazilir_mesaj_gitmez(): void
    {
        $id = $this->musteri();
        $sms = $this->sms();
        $sms->shouldNotReceive('panelBildirimli');

        $this->isle('musteri_kayit', ['user_id' => $id, 'yeni_hesap' => true]);

        $this->assertNotSame('eski', DB::table('users')->where('id', $id)->value('password'));
    }

    public function test_musteri_kayit_yeni_hesap_degilse_sifre_ve_mesaj_yok_cinsiyet_doluysa_dokunulmaz(): void
    {
        DB::table('salon_sms_ayarlari')->insert(['salon_id' => self::SALON, 'ayar_id' => 4, 'musteri' => 1]);
        $id = $this->musteri(['cinsiyet' => '1']);
        $sms = $this->sms();
        $sms->shouldNotReceive('panelBildirimli');

        $this->isle('musteri_kayit', ['user_id' => $id, 'cinsiyet_tahmin' => true]);

        $satir = DB::table('users')->where('id', $id)->first();
        $this->assertSame(['eski', '1'], [$satir->password, (string) $satir->cinsiyet]);
    }

    public function test_musteri_kayit_musteri_yoksa_hata(): void
    {
        $this->hataBekle('musteri_kayit', ['user_id' => 8], 'Musteri bulunamadi: 8');
        $this->hataBekle('kara_liste_bildirim', [], 'Musteri bulunamadi: -');
    }

    // ---- kara_liste_bildirim

    public function test_kara_liste_ayar_kapali_ya_da_portfoy_yoksa_gitmez(): void
    {
        $id = $this->musteri();
        $sms = $this->sms();
        $sms->shouldNotReceive('panelBildirimli');

        $this->isle('kara_liste_bildirim', ['user_id' => $id]);

        DB::table('salon_sms_ayarlari')->insert(['salon_id' => self::SALON, 'ayar_id' => 15, 'musteri' => 1]);
        $this->isle('kara_liste_bildirim', ['user_id' => $id]);
    }

    public function test_kara_liste_metin_tip_ve_muaf_push(): void
    {
        $id = $this->musteri();
        DB::table('salon_sms_ayarlari')->insert(['salon_id' => self::SALON, 'ayar_id' => 15, 'musteri' => 1]);
        DB::table('musteri_portfoy')->insert(['user_id' => $id, 'salon_id' => self::SALON, 'kara_liste' => 1]);
        $this->cihaz(['user_id' => $id]);
        $metin = 'Sayın Ayşe Kaya, isteğiniz doğrultusunda telefon numaranız kara listeye alınmıştır. '
            .'Kampanya ve reklam mesajları gönderilmeyecektir. Detaylı bilgi için bize ulaşınız. 02321112233';
        $sms = $this->sms();
        $sms->shouldReceive('panelBildirimli')->once()->with([[
            'to' => self::TEL, 'message' => $metin, 'user_id' => $id, 'gonderim_tipi' => 'kara_liste_bildirim',
        ]], false, 1, false, self::SALON, 'kara_liste_bildirim');

        $this->isle('kara_liste_bildirim', ['user_id' => $id]);

        $this->assertCount(1, $this->push, 'Kara listedeki musteriye bu push yine de gider');
        $this->assertSame('Kara Listeye Alındınız', $this->push[0]['notification']['title']);
        $this->assertSame($metin, $this->push[0]['notification']['body']);
        $this->assertSame('system_announcement', $this->push[0]['data']['type']);
    }

    // ---- ders_bildirim

    public function test_ders_eksik_veride_hata(): void
    {
        $this->hataBekle('ders_bildirim', ['user_id' => 1, 'mesaj' => 'x'], 'ders_bildirim: musteri/salon/mesaj yok');
        $this->hataBekle('ders_bildirim', ['user_id' => $this->musteri(), 'mesaj' => '  '], 'ders_bildirim: musteri/salon/mesaj yok');
    }

    public function test_ders_whatsapp_basariliysa_sms_gitmez_uygulama_daveti_eklenir(): void
    {
        $this->waBagla();
        $id = $this->musteri();
        $wa = $this->whatsapp();
        $wa->shouldReceive('gonder')->once()->with(Mockery::any(), self::TEL, Mockery::on(fn ($m) => str_starts_with($m, "Ders\n\n📲 *Uygulamamızı İndirin!*\n") && str_ends_with($m, 'https://panel.test/indir/20')),
            null, $id, null, false, 'ders_iptal')->andReturn(['ok' => true]);
        $sms = $this->sms();
        $sms->shouldNotReceive('gonder');

        $this->isle('ders_bildirim', ['user_id' => $id, 'mesaj' => 'Ders', 'gonderim_tipi' => 'ders_iptal']);
    }

    public function test_ders_whatsapp_basarisizsa_salon_onekli_sms_ve_varsayilan_tip(): void
    {
        $this->waBagla();
        $id = $this->musteri();
        $wa = $this->whatsapp();
        $wa->shouldReceive('gonder')->once()->with(Mockery::any(), self::TEL, Mockery::any(), null, $id, null, false, 'ders_bildirim')->andReturn(['ok' => false, 'error' => 'kontor-yetersiz']);
        $sms = $this->sms();
        $sms->shouldReceive('gonder')->once()->with(self::SALON, [['to' => self::TEL, 'message' => 'Test Salon - Ders']]);

        $this->isle('ders_bildirim', ['user_id' => $id, 'mesaj' => 'Ders']);
    }

    public function test_ders_kanal_kapaliysa_whatsapp_denenmez_sms_hatasi_yutulur(): void
    {
        $wa = $this->whatsapp();
        $wa->shouldNotReceive('gonder');
        $sms = $this->sms();
        $sms->shouldReceive('gonder')->once()->andThrow(new RuntimeException('saglayici'));

        $this->isle('ders_bildirim', ['user_id' => $this->musteri(), 'mesaj' => 'Ders']);
    }

    public function test_ders_cloud_api_kimlik_varsa_kanal_acik(): void
    {
        DB::table('salonlar')->where('id', self::SALON)->update(['whatsapp_saglayici' => 'cloud_api', 'cloud_api_token' => 't', 'cloud_api_phone_number_id' => 'p']);
        $wa = $this->whatsapp();
        $wa->shouldReceive('gonder')->once()->andReturn(['ok' => true]);
        $sms = $this->sms();
        $sms->shouldNotReceive('gonder');

        $this->isle('ders_bildirim', ['user_id' => $this->musteri(), 'mesaj' => 'Ders']);
    }

    public function test_ders_telefon_yoksa_sessiz(): void
    {
        $sms = $this->sms();
        $sms->shouldNotReceive('gonder');

        $this->isle('ders_bildirim', ['user_id' => $this->musteri(['cep_telefon' => null]), 'mesaj' => 'Ders']);
    }

    // ---- whatsapp_mesaj

    public function test_whatsapp_mesaj_eksik_veride_hata(): void
    {
        $this->hataBekle('whatsapp_mesaj', ['user_id' => $this->musteri(['cep_telefon' => '']), 'mesaj' => 'x'], 'whatsapp_mesaj: musteri/telefon/salon/mesaj yok');
    }

    public function test_whatsapp_mesaj_kalici_hata_yeniden_denenmez_servis_hatasi_denenir(): void
    {
        $id = $this->musteri();
        $wa = $this->whatsapp();
        $wa->shouldReceive('gonder')->with(Mockery::any(), self::TEL, 'selam', null, $id, null, false, 'manuel_musteri')
            ->andReturn(['ok' => false, 'error' => 'daily-cap-reached'], ['ok' => false, 'error' => 'service-unreachable'], ['ok' => true]);

        $this->isle('whatsapp_mesaj', ['user_id' => $id, 'mesaj' => 'selam']);
        $this->hataBekle('whatsapp_mesaj', ['user_id' => $id, 'mesaj' => 'selam'], 'whatsapp_mesaj: servis erisilemedi');
        $this->isle('whatsapp_mesaj', ['user_id' => $id, 'mesaj' => 'selam']);
    }

    // ---- sistem_bildirim

    private function sistemAyari(array $ayar): void
    {
        $yol = tempnam(sys_get_temp_dir(), 'sistem_wa');
        file_put_contents($yol, json_encode($ayar));
        config(['sistem.wa_ayar_dosyasi' => $yol]);
    }

    public function test_sistem_kapali_ya_da_alici_yoksa_hata(): void
    {
        $this->sistemAyari(['numara' => '905321234567', 'aktif' => false]);
        $this->hataBekle('sistem_bildirim', ['mesaj' => 'x'], 'sistem_bildirim: kapali-veya-numara-yok');

        $this->sistemAyari(['numara' => '', 'aktif' => true]);
        $this->hataBekle('sistem_bildirim', ['mesaj' => 'x'], 'sistem_bildirim: kapali-veya-numara-yok');
    }

    public function test_sistem_her_aliciya_wa_ve_efetech_sms_yerel_numarayla_platform_sms_kullanilmaz(): void
    {
        $this->sistemAyari(['numara' => '05321234567,5339998877', 'aktif' => true, 'session_id' => 'sistem-1']);
        $kopru = $this->mock(WhatsappKoprusu::class);
        $kopru->shouldReceive('gonder')->once()->with('sistem-1', '905321234567', 'talep')->andReturn(new KopruCevabi(202));
        $kopru->shouldReceive('gonder')->once()->with('sistem-1', '905339998877', 'talep')->andThrow(new RuntimeException('kopru'));
        $sms = $this->sms();
        $sms->shouldReceive('gonder')->once()->with('', [['to' => '5321234567', 'message' => 'talep']]);
        $sms->shouldReceive('gonder')->once()->with('', [['to' => '5339998877', 'message' => 'talep']])->andThrow(new RuntimeException('sms'));
        $platform = $this->mock(PlatformSms::class);
        $platform->shouldNotReceive('gonder');

        $this->isle('sistem_bildirim', ['mesaj' => 'talep']);
    }

    // ---- sms

    public function test_sms_bos_mesajda_hata_gecerliler_api_yoluna_gider(): void
    {
        $this->hataBekle('sms', ['mesajlar' => [['to' => '', 'message' => 'x'], ['to' => self::TEL, 'message' => '']]], 'sms: mesaj yok');

        $gecerli = ['to' => self::TEL, 'message' => 'kasa'];
        $sms = $this->sms();
        $sms->shouldReceive('apiBildirimli')->once()->with([$gecerli], false, 1, true, self::SALON);
        $sms->shouldReceive('apiBildirimli')->once()->with([$gecerli], false, 1, false, self::SALON);

        $this->isle('sms', ['mesajlar' => [['to' => null, 'message' => 'x'], $gecerli], 'dogrulama' => true]);
        $this->isle('sms', ['mesajlar' => [$gecerli]]);
    }

    // ---- form_link

    public function test_form_link_mesaj_yoksa_hata(): void
    {
        $this->hataBekle('form_link', ['mesajlar' => []], 'form_link: mesaj yok');
    }

    public function test_form_link_whatsapp_kapaliysa_tum_liste_sms(): void
    {
        $m = ['to' => self::TEL, 'message' => 'link'];
        $wa = $this->whatsapp();
        $wa->shouldNotReceive('acilGonder');
        $sms = $this->sms();
        $sms->shouldReceive('gonder2')->once()->with([$m], true, 6, true, self::SALON, false);

        $this->isle('form_link', ['mesajlar' => [$m], 'sms_bildirim' => true]);
    }

    public function test_form_link_paylasilan_oturum_kontrolu_salonun_kendi_satirinda(): void
    {
        DB::table('salonlar')->insert(['id' => 30, 'whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);
        DB::table('salonlar')->where('id', self::SALON)->update(['whatsapp_paylasilan_salon_id' => 30]);
        $m = ['to' => self::TEL, 'message' => 'link'];
        $wa = $this->whatsapp();
        $wa->shouldNotReceive('acilGonder');
        $sms = $this->sms();
        $sms->shouldReceive('gonder2')->once()->with([$m], false, 6, true, self::SALON, false);

        $this->isle('form_link', ['mesajlar' => [$m]]);
    }

    public function test_form_link_yalniz_basarisiz_mesaj_sms_e_duser(): void
    {
        $this->waBagla();
        $a = ['to' => '5321111111', 'message' => 'a'];
        $b = ['to' => '5322222222', 'message' => 'b'];
        $wa = $this->whatsapp();
        $wa->shouldReceive('acilGonder')->once()->with(Mockery::any(), '5321111111', 'a', 5)->andReturn(['ok' => true]);
        $wa->shouldReceive('acilGonder')->once()->with(Mockery::any(), '5322222222', 'b', 5)->andReturn(['ok' => false, 'error' => 'x']);
        $sms = $this->sms();
        $sms->shouldReceive('gonder2')->once()->with([$b], false, 6, true, self::SALON, false);

        $this->isle('form_link', ['mesajlar' => [$a, $b], 'user_id' => 5]);
    }

    public function test_form_link_tamami_whatsapp_ile_giderse_sms_yok_istisnada_tum_liste_sms(): void
    {
        $this->waBagla();
        $a = ['to' => '5321111111', 'message' => 'a'];
        $b = ['to' => '5322222222', 'message' => 'b'];
        $wa = $this->whatsapp();
        $wa->shouldReceive('acilGonder')->with(Mockery::any(), '5321111111', 'a', null)->andReturn(['ok' => true]);
        $wa->shouldReceive('acilGonder')->with(Mockery::any(), '5322222222', 'b', null)->andReturn(['ok' => true], ['ok' => true]);
        $sms = $this->sms();
        $sms->shouldNotReceive('gonder2');
        $this->isle('form_link', ['mesajlar' => [$a, $b]]);

        $wa2 = $this->whatsapp();
        $wa2->shouldReceive('acilGonder')->with(Mockery::any(), '5321111111', 'a', null)->andReturn(['ok' => true]);
        $wa2->shouldReceive('acilGonder')->with(Mockery::any(), '5322222222', 'b', null)->andThrow(new \RuntimeException('kopuk'));
        $sms2 = $this->sms();
        $sms2->shouldReceive('gonder2')->once()->with([$a, $b], false, 6, true, self::SALON, false);
        $this->isle('form_link', ['mesajlar' => [$a, $b]]);
    }
}
