<?php

namespace Tests\Feature\Konsol;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\DogrulamaKoduIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\KvkkKoduIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\OngorusmeKayitIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\RandevuIptalIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\RandevuKayitIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\RandevuOnayIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\RandevuTasindiIsleyici;
use App\Services\BildirimKuyrugu\Isleyici\SeansKullanimIsleyici;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Randevu turleri: metin, kanal sirasi, ayar kapisi, yeniden deneme. Saglayicilar Http::fake; FCM token'i yok, ag istegi yok. */
class RandevuBildirimIsleyicileriTest extends TestCase
{
    private const TABLOLAR = [
        'salonlar' => ['yeni_sms', 'sms_user_name', 'sms_secret', 'sms_apikey', 'sms_baslik', 'randevu_sms_hatirlatma', 'kara_liste_aktif', 'salon_adi', 'telefon_1', 'kvkk_link', 'konum_linki', 'salon_turu_id',
            'uygulamalar_kisa_link', 'app_bundle', 'firebase_profile', 'whatsapp_aktif', 'whatsapp_durum', 'whatsapp_numara', 'whatsapp_kontor', 'whatsapp_deneme_bitis', 'whatsapp_saglayici',
            'whatsapp_paylasilan_salon_id', 'whatsapp_baglanti_tarihi', 'whatsapp_warmup_baslangic', 'whatsapp_gunluk_limit', 'whatsapp_son_hata', 'created_at', 'updated_at'],
        'users' => ['name', 'cep_telefon'],
        'isletmeyetkilileri' => ['name', 'gsm1', 'profil_resim'],
        'salon_personelleri' => ['salon_id', 'yetkili_id', 'role_id'],
        'randevular' => ['user_id', 'salon_id', 'tarih', 'saat', 'durum', 'uygulama', 'web', 'olusturan_personel_id', 'dogrulama', 'on_gorusme_id', 'created_at', 'updated_at'],
        'randevu_hizmetler' => ['randevu_id', 'hizmet_id', 'personel_id', 'saat', 'saat_bitis', 'created_at', 'updated_at'],
        'hizmetler' => ['hizmet_adi'],
        'paketler' => ['paket_adi'],
        'urunler' => ['urun_adi'],
        'paket_hizmetler' => ['paket_id', 'hizmet_id'],
        'adisyon_paketler' => ['paket_id', 'seans_sayisi'],
        'adisyon_hizmetler' => ['hizmet_id', 'seans_sayisi'],
        'adisyon_paket_seanslar' => ['adisyon_paket_id', 'adisyon_hizmet_id', 'hizmet_id', 'randevu_id', 'geldi'],
        'salon_sms_ayarlari' => ['salon_id', 'ayar_id', 'musteri', 'personel'],
        'bildirimler' => ['salon_id', 'user_id', 'personel_id', 'aciklama', 'url', 'img_src', 'tarih_saat', 'okundu', 'butonlar', 'randevu_id', 'baslik', 'tip', 'deep_link', 'image_url', 'popup', 'extra_data', 'created_at', 'updated_at'],
        'bildirim_kimlikleri' => ['user_id', 'isletme_yetkili_id', 'bildirim_id', 'aktif', 'app_bundle', 'platform', 'gonderim_hatalari', 'son_kullanim_tarihi', 'created_at', 'updated_at'],
        'musteri_portfoy' => ['user_id', 'salon_id', 'kara_liste', 'onay_kodu'],
        'sms_iletim_raporlari' => ['salon_id', 'tur', 'aciklama', 'rapor_id', 'adet', 'kredi', 'durum', 'created_at', 'updated_at'],
        'whatsapp_gonderim_loglari' => ['salon_id', 'user_id', 'randevu_id', 'telefon', 'mesaj', 'durum', 'hata', 'provider', 'gonderim_tipi', 'mesaj_id', 'gonderim_tarihi', 'created_at', 'updated_at'],
        'on_gorusmeler' => ['salon_id', 'user_id', 'personel_id', 'ad_soyad', 'cep_telefon', 'paket_id', 'urun_id', 'hizmet_id'],
    ];

    private const MUSTERI_TEL = '5321234567';

    private const PERSONEL_TEL = '5339876543';

    private int $salonId;

    private int $randevuId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABLOLAR as $tablo => $kolonlar) {
            Schema::dropIfExists($tablo);
            Schema::create($tablo, function (Blueprint $t) use ($kolonlar) {
                $t->id();
                foreach ($kolonlar as $kolon) {
                    in_array($kolon, ['geldi', 'seans_sayisi'], true) ? $t->integer($kolon)->nullable() : $t->text($kolon)->nullable();
                }
            });
        }
        config([
            'sms.efetech.gonderim_url' => 'https://efetech.test/gonder',
            'sms.efetech.rapor_url' => 'https://efetech.test/rapor',
            'sms.efetech.bakiye_url' => 'https://efetech.test/bakiye',
            'sms.voicetelekom.gonderim_url' => 'http://vt.test/sms/create',
            'sms.voicetelekom.kredi_url' => 'http://vt.test/user/credit',
            'whatsapp.kopru_url' => 'http://kopru.test',
            'whatsapp.business_hours.enforce' => false,
            'panel.eski_panel_url' => 'https://panel.test',
        ]);

        $this->salonId = (int) DB::table('salonlar')->insertGetId([
            'salon_adi' => 'Salon A', 'telefon_1' => '2321112233', 'kvkk_link' => 'https://k.test', 'sms_baslik' => 'BASLIK', 'sms_apikey' => 'ANAHTAR', 'yeni_sms' => 0, 'whatsapp_deneme_bitis' => '2099-01-01',
        ]);
        DB::table('users')->insert(['id' => 7, 'name' => 'Ayse Kaya', 'cep_telefon' => self::MUSTERI_TEL]);
        DB::table('isletmeyetkilileri')->insert(['id' => 40, 'name' => 'Veli Yetkili', 'gsm1' => self::PERSONEL_TEL, 'profil_resim' => 'r.png']);
        DB::table('salon_personelleri')->insert(['id' => 30, 'salon_id' => $this->salonId, 'yetkili_id' => 40, 'role_id' => 5]);
        DB::table('hizmetler')->insert(['id' => 9, 'hizmet_adi' => 'Cilt Bakimi']);
        $this->randevuId = (int) DB::table('randevular')->insertGetId([
            'user_id' => 7, 'salon_id' => $this->salonId, 'tarih' => '2099-05-19', 'saat' => '10:00:00', 'durum' => 1, 'olusturan_personel_id' => 40,
        ]);
        DB::table('randevu_hizmetler')->insert(['id' => 50, 'randevu_id' => $this->randevuId, 'hizmet_id' => 9, 'personel_id' => 30, 'saat' => '10:30:00', 'saat_bitis' => '11:00:00']);
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TABLOLAR) as $tablo) {
            Schema::dropIfExists($tablo);
        }
        parent::tearDown();
    }

    private function saglayicilar(int $kopruKodu = 202, mixed $bakiye = ['response' => ['balance' => 5]]): void
    {
        Http::fake([
            'efetech.test/gonder' => Http::response(['response' => ['message' => ['id' => 77, 'count' => 1, 'total_price' => 1.5]]]),
            'efetech.test/rapor' => Http::response(['response' => ['message' => ['status' => 11]]]),
            'efetech.test/bakiye' => Http::response($bakiye),
            'kopru.test/*' => Http::response(['error' => 'x'], $kopruKodu),
        ]);
    }

    private function ayar(int $ayarId, int $musteri = 1, int $personel = 1): void
    {
        DB::table('salon_sms_ayarlari')->insert(['salon_id' => $this->salonId, 'ayar_id' => $ayarId, 'musteri' => $musteri, 'personel' => $personel]);
    }

    private function whatsappBagla(): void
    {
        DB::table('salonlar')->where('id', $this->salonId)->update(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected']);
    }

    /** @param  class-string<BildirimIsleyici>  $sinif */
    private function isle(string $sinif, array $payload = [], ?int $yetkiliId = 40): void
    {
        app($sinif)->isle((object) [
            'id' => 1, 'salon_id' => $this->salonId, 'tur' => 'test', 'randevu_id' => $this->randevuId, 'yetkili_id' => $yetkiliId, 'durum' => 3, 'deneme' => 0,
        ], $payload);
    }

    /** @return array<int, array{to:mixed, message:string}> Efetech'e giden mesajlar */
    private function smsler(): array
    {
        $liste = [];
        foreach (Http::recorded(fn (Request $r) => str_contains($r->url(), 'efetech.test/gonder')) as [$istek]) {
            foreach ($istek['messages'] as $m) {
                $liste[] = ['to' => $m['to'], 'message' => $m['message']];
            }
        }

        return $liste;
    }

    /** @return string[] kopruye giden metinler */
    private function whatsapplar(): array
    {
        return array_map(fn ($c) => $c[0]['message'], Http::recorded(fn (Request $r) => str_contains($r->url(), 'kopru.test'))->all());
    }

    /** @return string[] basligi olan satirlar push'tan yazilir, olmayanlar uygulama ici bildirimdir */
    private function pushBasliklari(): array
    {
        return DB::table('bildirimler')->whereNotNull('baslik')->orderBy('id')->pluck('baslik')->all();
    }

    public function test_iptal_metinleri_ayar_acikken_sms_ve_bildirimler(): void
    {
        $this->saglayicilar();
        $this->ayar(3);

        $this->isle(RandevuIptalIsleyici::class);

        $musteri = 'Salon A için oluşturulan 19.05.2099 10:00 randevunuz iptal edilmiştir. Detaylı bilgi için bize ulaşın. 02321112233';
        $personel = 'Ayse Kaya isimli müşterinin 19.05.2099 10:30 Cilt Bakimi randevusu Veli Yetkili tarafından iptal edilmiştir.';
        $this->assertSame([['to' => self::MUSTERI_TEL, 'message' => $musteri], ['to' => self::PERSONEL_TEL, 'message' => $personel]], $this->smsler());
        $this->assertSame(['Randevu İptal Edildi', 'Randevunuz İptal Edildi'], $this->pushBasliklari());
        $this->assertSame(1, DB::table('bildirimler')->whereNull('baslik')->where('user_id', 7)->where('aciklama', $musteri)->where('url', '#')->where('img_src', 'r.png')->count());
        $this->assertSame(1, DB::table('bildirimler')->whereNull('baslik')->where('personel_id', 30)->where('aciklama', $personel)->count());
    }

    public function test_iptal_personel_metni_iptal_edeni_yazar_olusturani_degil(): void
    {
        $this->saglayicilar();
        $this->ayar(3);
        DB::table('isletmeyetkilileri')->insert(['id' => 41, 'name' => 'Ayse Mudur', 'gsm1' => '5551112233']);

        $this->isle(RandevuIptalIsleyici::class, [], 41);

        $this->assertSame('Ayse Kaya isimli müşterinin 19.05.2099 10:30 Cilt Bakimi randevusu Ayse Mudur tarafından iptal edilmiştir.', $this->smsler()[1]['message']);
    }

    public function test_red_metni_ve_musteri_push_red_bayragi(): void
    {
        $this->saglayicilar();
        $this->ayar(3, 1, 0);

        $this->isle(RandevuIptalIsleyici::class, ['red' => true]);

        $this->assertSame([[
            'to' => self::MUSTERI_TEL,
            'message' => 'Salon A için oluşturduğunuz 19.05.2099 10:00 randevu talebiniz reddedilmiştir. Detaylı bilgi için bize ulaşın. 02321112233',
        ]], $this->smsler());
        $this->assertSame(['Randevu Reddedildi', 'Randevu Talebiniz Reddedildi'], $this->pushBasliklari());
        $this->assertSame('{"iptal_eden":"salon","red":"1"}', DB::table('bildirimler')->where('baslik', 'Randevu Talebiniz Reddedildi')->value('extra_data'));
        $this->assertSame('Ayse Kaya için 19.05.2099 10:30 Cilt Bakimi randevusu Veli Yetkili tarafından reddedilmiştir.', DB::table('bildirimler')->where('baslik', 'Randevu Reddedildi')->value('aciklama'));
    }

    public function test_musteri_kendi_iptal_ettiyse_musteriye_push_gitmez(): void
    {
        $this->saglayicilar();
        DB::table('randevular')->where('id', $this->randevuId)->update(['durum' => 3]);

        $this->isle(RandevuIptalIsleyici::class);

        $this->assertSame(['Müşteri Randevuyu İptal Etti'], $this->pushBasliklari());
        $this->assertSame('Ayse Kaya 19.05.2099 10:30 Cilt Bakimi randevusunu iptal etmiştir.', DB::table('bildirimler')->where('baslik', 'Müşteri Randevuyu İptal Etti')->value('aciklama'));
        $this->assertSame([], $this->smsler());
    }

    public function test_iptal_whatsapp_kuyruga_aldiysa_sms_gitmez_tip_iptal(): void
    {
        $this->saglayicilar(202);
        $this->ayar(3);
        $this->whatsappBagla();

        $this->isle(RandevuIptalIsleyici::class);

        $this->assertCount(2, $this->whatsapplar());
        $this->assertSame([], $this->smsler());
        $this->assertSame(['iptal_bildirim'], DB::table('whatsapp_gonderim_loglari')->distinct()->pluck('gonderim_tipi')->all());
    }

    public function test_iptal_whatsapp_basarisizsa_sms_duser(): void
    {
        $this->saglayicilar(500);
        $this->ayar(3);
        $this->whatsappBagla();

        $this->isle(RandevuIptalIsleyici::class);

        $this->assertCount(2, $this->smsler());
    }

    public function test_hizmet_kaydi_yoksa_iptal_hata_firlatir_yeniden_denenir(): void
    {
        $this->saglayicilar();
        DB::table('hizmetler')->delete();

        $this->expectException(\Throwable::class);
        $this->isle(RandevuIptalIsleyici::class);
    }

    public function test_randevu_yoksa_taban_eski_hata_metniyle_firlatir(): void
    {
        DB::table('randevular')->delete();

        $this->expectExceptionMessage('Randevu bulunamadi: '.$this->randevuId);
        $this->isle(RandevuIptalIsleyici::class);
    }

    public function test_onay_whatsapp_oncelikli_bagliysa_sms_gitmez_metinler(): void
    {
        $this->saglayicilar();
        $this->ayar(2);
        $this->whatsappBagla();

        $this->isle(RandevuOnayIsleyici::class);

        $musteri = 'Salon A için oluşturduğunuz 19.05.2099 10:00 tarihli randevu talebiniz onaylanmıştır. Randevunuza 15 dk önce gelmenizi rica ederiz. Detaylı bilgi için bize ulaşın. 02321112233';
        $personel = 'Ayse Kaya isimli müşterinin 19 Mayıs Salı günü saat 10:30 Cilt Bakimi randevusu Veli Yetkili tarafından onaylanmıştır.';
        $this->assertSame([$musteri, $personel], $this->whatsapplar());
        $this->assertSame([], $this->smsler());
        $this->assertSame(['Randevu Onaylandı', 'Randevunuz Onaylandı'], $this->pushBasliklari());
        $this->assertSame(0, DB::table('bildirimler')->whereNull('baslik')->where('user_id', 7)->count());
    }

    public function test_onay_whatsapp_kapaliysa_sms(): void
    {
        $this->saglayicilar();
        $this->ayar(2);

        $this->isle(RandevuOnayIsleyici::class);

        $this->assertSame([], $this->whatsapplar());
        $this->assertCount(2, $this->smsler());
    }

    public function test_dogrulama_whatsapp_bagliysa_acil_whatsapp_sms_gitmez(): void
    {
        $this->saglayicilar();
        $this->whatsappBagla();

        $this->isle(DogrulamaKoduIsleyici::class);

        $kod = DB::table('randevular')->where('id', $this->randevuId)->value('dogrulama');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $kod);
        $this->assertSame(['Doğrulama kodunuz : '.$kod], $this->whatsapplar());
        $this->assertSame([], $this->smsler());
        $this->assertSame('geldi_dogrulama_kodu', DB::table('whatsapp_gonderim_loglari')->value('gonderim_tipi'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'kopru.test') && $r['urgent'] === true);
    }

    public function test_dogrulama_whatsapp_kapaliysa_sms_rapor_yok(): void
    {
        $this->saglayicilar();

        $this->isle(DogrulamaKoduIsleyici::class);

        $kod = DB::table('randevular')->where('id', $this->randevuId)->value('dogrulama');
        $this->assertSame([['to' => self::MUSTERI_TEL, 'message' => 'Doğrulama kodunuz : '.$kod]], $this->smsler());
        $this->assertSame(0, DB::table('sms_iletim_raporlari')->count());
    }

    public function test_dogrulama_whatsapp_gidemezse_sms(): void
    {
        $this->saglayicilar(500);
        $this->whatsappBagla();

        $this->isle(DogrulamaKoduIsleyici::class);

        $this->assertCount(1, $this->smsler());
    }

    public function test_dogrulama_paylasilan_oturumlu_alt_salon_ust_salonun_whatsappindan_gider(): void
    {
        $this->saglayicilar();
        $ust = (int) DB::table('salonlar')->insertGetId(['whatsapp_aktif' => 1, 'whatsapp_durum' => 'connected', 'whatsapp_deneme_bitis' => '2099-01-01']);
        DB::table('salonlar')->where('id', $this->salonId)->update(['whatsapp_paylasilan_salon_id' => $ust]);

        $this->isle(DogrulamaKoduIsleyici::class);

        $this->assertCount(1, $this->whatsapplar());
        $this->assertSame([], $this->smsler());
    }

    public function test_kvkk_onay_kodu_yoksa_eski_hata(): void
    {
        $this->expectExceptionMessage('Portfoy onay_kodu yok');
        $this->isle(KvkkKoduIsleyici::class);
    }

    public function test_kvkk_whatsapp_bagliysa_acil_whatsapp(): void
    {
        $this->saglayicilar();
        $this->whatsappBagla();
        DB::table('musteri_portfoy')->insert(['user_id' => 7, 'salon_id' => $this->salonId, 'onay_kodu' => '1234']);

        $this->isle(KvkkKoduIsleyici::class);

        $this->assertCount(1, $this->whatsapplar());
        $this->assertSame([], $this->smsler());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'kopru.test') && $r['urgent'] === true);
    }

    public function test_kvkk_metni_birebir_rapor_yazilmaz(): void
    {
        $this->saglayicilar();
        DB::table('musteri_portfoy')->insert(['user_id' => 7, 'salon_id' => $this->salonId, 'onay_kodu' => '1234']);

        $this->isle(KvkkKoduIsleyici::class);

        $this->assertSame([[
            'to' => self::MUSTERI_TEL,
            'message' => "Sn. Ayse Kaya, 1234 numarali onay kodunu ilgiliye soyleyerek, https://k.test kvkk linkinde yer alan KVKK Aydinlatma Metni'ne gore kisisel verilerinizin islenmesine ve sistemlerimizde kayitli iletisim adresleriniz uzerinden SMS, e-posta ve aramalar vasitasiyla ticari elektronik ileti gonderimine izin vermis olacaksiniz",
        ]], $this->smsler());
        $this->assertSame(0, DB::table('sms_iletim_raporlari')->count());
    }

    private function kayitPayload(array $istek = [], array $ek = []): array
    {
        return $ek + [
            'randevu_idler' => [$this->randevuId],
            'istek' => $istek + ['randevu_tarihi' => '2099-05-19', 'randevu_saati' => '10:00', 'randevuKaynak' => 'salon', 'durum' => 1, 'olusturan' => 40],
        ];
    }

    public function test_kayit_salon_olusturdu_personel_metni_personele_hitap_eder_push(): void
    {
        $this->saglayicilar();
        $this->ayar(12);

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload());

        $sms = $this->smsler();
        $this->assertSame('Salon A tarafından 19.05.2099 10:00 olarak randevunuz oluşturulmuştur. Randevunuza 15 dk önce gelmenizi rica ederiz. Detaylı bilgi için bize ulaşın. 02321112233', $sms[0]['message']);
        $this->assertSame('Ayse Kaya isimli müşterinin 19.05.2099 - 10:30 Cilt Bakimi randevusu Veli Yetkili tarafından oluşturulmuştur.', $sms[1]['message']);
        $this->assertSame(self::PERSONEL_TEL, $sms[1]['to']);
        $this->assertSame(['Sizin için randevu oluşturuldu'], $this->pushBasliklari());
        $this->assertSame(2, DB::table('bildirimler')->whereNull('baslik')->count());
    }

    public function test_kayit_durum_yoksa_talep_sayilir_eski_int_null_kurali(): void
    {
        $this->saglayicilar();
        $this->ayar(12, 1, 0);
        $payload = $this->kayitPayload();
        unset($payload['istek']['durum']);

        $this->isle(RandevuKayitIsleyici::class, $payload);

        $this->assertSame('Salon A için 19.05.2099 10:00 tarihli randevu talebiniz alınmıştır. Talebiniz salon tarafından onaylandığında ayrıca bilgilendirileceksiniz. Detaylı bilgi için bize ulaşın. 02321112233', $this->smsler()[0]['message']);
    }

    public function test_kayit_uygulama_kaynagi_talep_pushlari_yoneticiler_dahil(): void
    {
        $this->saglayicilar();
        DB::table('salon_personelleri')->insert(['id' => 31, 'salon_id' => $this->salonId, 'yetkili_id' => 40, 'role_id' => 1]);

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload(['randevuKaynak' => 'uygulama', 'durum' => 0]));

        $this->assertSame(['Randevu Talebiniz Alındı', 'Yeni randevu talebi', 'Yeni randevu talebi'], $this->pushBasliklari());
        $this->assertSame('Salon A • 19.05.2099 10:00 • Onay bekleniyor', DB::table('bildirimler')->where('baslik', 'Randevu Talebiniz Alındı')->value('aciklama'));
    }

    public function test_kayit_whatsapp_yeni_olusturmada_zengin_guncellemede_kisa(): void
    {
        $this->saglayicilar(202);
        $this->ayar(12, 1, 0);
        $this->whatsappBagla();

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload());

        $wa = $this->whatsapplar();
        $this->assertStringContainsString('için randevunuz başarıyla oluşturulmuştur', $wa[0]);
        $this->assertStringContainsString('Cilt Bakimi', $wa[0]);
        $this->assertStringContainsString('Uygulamamızı İndirin', $wa[0]);
        $this->assertSame('yeni_randevu_bildirim', DB::table('whatsapp_gonderim_loglari')->value('gonderim_tipi'));
        $this->assertSame([], $this->smsler());
    }

    public function test_kayit_guncellemede_degisiklik_yoksa_mesaj_gitmez_musteri_push_gider(): void
    {
        $this->saglayicilar();
        $this->ayar(12);

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload([], ['guncelleme' => true]));

        $this->assertSame([], $this->smsler());
        $this->assertSame(['Randevunuz güncellendi'], $this->pushBasliklari());
    }

    public function test_kayit_guncelleme_zaman_degisti_musteri_ve_personel_push(): void
    {
        $this->saglayicilar();
        $this->ayar(12, 1, 0);

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload([], ['guncelleme' => true, 'zaman_degisti' => true]));

        $this->assertSame('Salon A tarafından 19.05.2099 10:00 olarak randevunuz güncellenmiştir. Randevunuza 15 dk önce gelmenizi rica ederiz. Detaylı bilgi için bize ulaşın. 02321112233', $this->smsler()[0]['message']);
        $this->assertSame(['Randevunuzun zamanı değişti', 'Randevu zamanı değişti'], $this->pushBasliklari());
        $this->assertSame('Ayse Kaya • Yeni: 19.05.2099 10:00', DB::table('bildirimler')->where('baslik', 'Randevu zamanı değişti')->value('aciklama'));
    }

    public function test_kayit_ayar_kapali_ertesi_gun_bypass_saat_kuralina_bagli(): void
    {
        $this->saglayicilar();
        $this->ayar(12, 0, 0);

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload(['randevu_tarihi' => date('Y-m-d', strtotime('+1 day')), 'randevu_saati' => '00:30']));

        // Eski kural: 18:00 sonrasi ve 24 saatten az kaldiysa gider. Saat date() ile okunur, test iki durumu da karsilar.
        $this->assertCount((int) date('G') >= 18 ? 1 : 0, $this->smsler());
    }

    public function test_kayit_salon_257de_yoneticiler_de_sms_alir(): void
    {
        $this->saglayicilar();
        DB::table('salonlar')->where('id', $this->salonId)->update(['id' => 257]);
        DB::table('randevular')->where('id', $this->randevuId)->update(['salon_id' => 257]);
        DB::table('salon_personelleri')->insert(['id' => 31, 'salon_id' => 257, 'yetkili_id' => 41, 'role_id' => 1]);
        DB::table('isletmeyetkilileri')->insert(['id' => 41, 'name' => 'Yonetici', 'gsm1' => '5551112233']);
        $this->salonId = 257;
        $this->ayar(12, 0, 1);

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload());

        $this->assertEqualsCanonicalizing([self::PERSONEL_TEL, '5551112233'], array_column($this->smsler(), 'to'));
    }

    public function test_kayit_tekrarli_randevuda_push_son_randevuya_silinen_atlanir(): void
    {
        $this->saglayicilar();
        $ikinci = (int) DB::table('randevular')->insertGetId(['user_id' => 7, 'salon_id' => $this->salonId, 'tarih' => '2099-05-26', 'saat' => '11:00:00', 'durum' => 1]);

        $this->isle(RandevuKayitIsleyici::class, $this->kayitPayload([], ['randevu_idler' => [$this->randevuId, 999999, $ikinci]]));

        $this->assertSame((string) $ikinci, DB::table('bildirimler')->where('baslik', 'Sizin için randevu oluşturuldu')->value('randevu_id'));
    }

    private function tasimaPayload(array $ek = []): array
    {
        return $ek + [
            'rh_id' => 50, 'eski_saat' => '10:30:00', 'eski_bitis' => '11:00:00', 'eski_personel_id' => 30,
            'yeni_saat' => '12:00:00', 'yeni_bitis' => '12:30:00', 'takvim_turu' => 1, 'resource_id' => null,
        ];
    }

    public function test_tasindi_zorunlu_payload_eksikse_hata_firlar(): void
    {
        $this->expectException(\Throwable::class);
        $this->isle(RandevuTasindiIsleyici::class, ['rh_id' => 50]);
    }

    public function test_tasindi_kalem_yoksa_sessiz_biter(): void
    {
        $this->saglayicilar();

        $this->isle(RandevuTasindiIsleyici::class, $this->tasimaPayload(['rh_id' => 999999]));

        $this->assertSame([], $this->pushBasliklari());
        Http::assertNothingSent();
    }

    public function test_tasindi_saat_degisti_metinler_ve_whatsapp_daveti_yalniz_wada(): void
    {
        $this->saglayicilar(500);
        $this->ayar(12);
        $this->whatsappBagla();

        $this->isle(RandevuTasindiIsleyici::class, $this->tasimaPayload(['resource_id' => 30]));

        $this->assertSame(['Randevunuzun zamanı değişti', 'Randevu zamanı değişti'], $this->pushBasliklari());
        $this->assertSame('Salon A • Cilt Bakimi • Yeni: 19.05.2099 12:00-12:30', DB::table('bildirimler')->where('baslik', 'Randevunuzun zamanı değişti')->value('aciklama'));
        $musteri = 'Salon A tarafından randevunuz 19.05.2099 12:00-12:30 olarak güncellendi.';
        $this->assertStringContainsString('Uygulamamızı İndirin', $this->whatsapplar()[0]);
        $this->assertSame([
            ['to' => self::MUSTERI_TEL, 'message' => $musteri],
            ['to' => self::PERSONEL_TEL, 'message' => 'Ayse Kaya isimli müşterinin Cilt Bakimi randevusu 19.05.2099 12:00-12:30 olarak güncellendi.'],
        ], $this->smsler());
    }

    public function test_tasindi_yalniz_sure_degisti_baslik_sure(): void
    {
        $this->saglayicilar();

        $this->isle(RandevuTasindiIsleyici::class, $this->tasimaPayload(['yeni_saat' => '10:30:00', 'resource_id' => 30]));

        $this->assertSame(['Randevunuzun süresi güncellendi', 'Randevu süresi güncellendi'], $this->pushBasliklari());
    }

    public function test_tasindi_personel_degisti_eskiye_alindi_yeniye_atandi(): void
    {
        $this->saglayicilar();
        DB::table('randevu_hizmetler')->where('id', 50)->update(['saat' => '12:00:00', 'saat_bitis' => '12:30:00']);

        $this->isle(RandevuTasindiIsleyici::class, $this->tasimaPayload(['eski_saat' => '12:00:00', 'eski_bitis' => '12:30:00', 'resource_id' => 31]));

        $this->assertSame(['Randevu sizden alındı', 'Size yeni randevu atandı'], $this->pushBasliklari());
        $this->assertSame('Ayse Kaya • Cilt Bakimi • 19.05.2099 12:00-12:30', DB::table('bildirimler')->where('baslik', 'Size yeni randevu atandı')->value('aciklama'));
    }

    private function seansHazirla(): void
    {
        $this->ayar(20);
        DB::table('adisyon_hizmetler')->insert(['id' => 60, 'hizmet_id' => 9, 'seans_sayisi' => 4]);
        DB::table('adisyon_paket_seanslar')->insert(['adisyon_hizmet_id' => 60, 'hizmet_id' => 9, 'randevu_id' => $this->randevuId, 'geldi' => 1]);
    }

    public function test_seans_ayar_kapaliysa_hic_kanal_yok(): void
    {
        $this->saglayicilar();
        DB::table('adisyon_hizmetler')->insert(['id' => 60, 'hizmet_id' => 9, 'seans_sayisi' => 4]);
        DB::table('adisyon_paket_seanslar')->insert(['adisyon_hizmet_id' => 60, 'randevu_id' => $this->randevuId, 'geldi' => 1]);

        $this->isle(SeansKullanimIsleyici::class);

        Http::assertNothingSent();
        $this->assertSame([], $this->pushBasliklari());
    }

    public function test_seans_son_bes_dakikada_gittiyse_atlar(): void
    {
        $this->saglayicilar();
        $this->seansHazirla();
        DB::table('whatsapp_gonderim_loglari')->insert(['salon_id' => $this->salonId, 'randevu_id' => $this->randevuId, 'gonderim_tipi' => 'seans_bildirim', 'created_at' => now()]);

        $this->isle(SeansKullanimIsleyici::class);

        Http::assertNothingSent();
    }

    public function test_seans_detayli_whatsapp_tek_yildizla_sms_gitmez(): void
    {
        $this->saglayicilar(202);
        $this->seansHazirla();
        $this->whatsappBagla();

        $this->isle(SeansKullanimIsleyici::class);

        $wa = $this->whatsapplar()[0];
        $this->assertStringContainsString("✨ *SEANS TAKİP BİLGİLENDİRMESİ* ✨\n\nMerhaba *Sayın Ayse Kaya*, 🌸", $wa);
        $this->assertStringContainsString("📦 *Hizmetiniz:* Cilt Bakimi (*4 Seans*)", $wa);
        $this->assertStringContainsString("• Tamamlanan: *1 / 4 Seans*\n• Kalan: *3 Seans*", $wa);
        $this->assertStringNotContainsString('**', $wa);
        $this->assertSame('seans_bildirim', DB::table('whatsapp_gonderim_loglari')->value('gonderim_tipi'));
        $this->assertSame([], $this->smsler());
        $this->assertSame(['Seans Bilgilendirmesi ✨'], $this->pushBasliklari());
        $this->assertStringContainsString('**SEANS TAKİP', DB::table('bildirimler')->where('baslik', 'Seans Bilgilendirmesi ✨')->value('aciklama'));
    }

    public function test_seans_push_ve_whatsapp_ulasmazsa_kisa_sms_yerel_numarayla(): void
    {
        $this->saglayicilar();
        $this->seansHazirla();
        DB::table('users')->where('id', 7)->update(['cep_telefon' => '+905321234567']);

        $this->isle(SeansKullanimIsleyici::class);

        $this->assertSame([['to' => self::MUSTERI_TEL, 'message' => 'Sayın Ayse Kaya, seans bilgileriniz güncellendi. Sağlıklı günler dileriz.']], $this->smsler());
    }

    public function test_seans_paket_blogu_kullanilan_kullanilmayan_ayrimi(): void
    {
        $this->saglayicilar(202);
        $this->ayar(20);
        $this->whatsappBagla();
        DB::table('paketler')->insert(['id' => 70, 'paket_adi' => 'Yaz Paketi']);
        DB::table('adisyon_paketler')->insert(['id' => 71, 'paket_id' => 70, 'seans_sayisi' => 5]);
        DB::table('hizmetler')->insert(['id' => 10, 'hizmet_adi' => 'Lazer']);
        DB::table('paket_hizmetler')->insert([['paket_id' => 70, 'hizmet_id' => 9], ['paket_id' => 70, 'hizmet_id' => 10]]);
        DB::table('adisyon_paket_seanslar')->insert(['adisyon_paket_id' => 71, 'hizmet_id' => 9, 'randevu_id' => $this->randevuId, 'geldi' => 1]);

        $this->isle(SeansKullanimIsleyici::class);

        $wa = $this->whatsapplar()[0];
        $this->assertStringContainsString('*Paketiniz:* Yaz Paketi (*5 Seans*)', $wa);
        $this->assertStringContainsString("*Bugün Kullanılan İşlem*\n*Cilt Bakimi*\n• Bu randevuda kullanılan: *1 Seans*", $wa);
        $this->assertStringContainsString("*Bugün Kullanılmayan İşlemler*\n• *Lazer:* Kalan *5 Seans*", $wa);
    }

    private function onGorusme(array $alan = []): int
    {
        return (int) DB::table('on_gorusmeler')->insertGetId($alan + [
            'salon_id' => $this->salonId, 'ad_soyad' => 'Can Demir', 'cep_telefon' => self::MUSTERI_TEL, 'personel_id' => 30,
        ]);
    }

    public function test_ongorusme_kayit_yoksa_eski_hata(): void
    {
        $this->expectExceptionMessage('On gorusme bulunamadi: 5');
        $this->isle(OngorusmeKayitIsleyici::class, ['on_gorusme_id' => 5]);
    }

    public function test_ongorusme_ayar_satiri_yoksa_sessiz(): void
    {
        $this->saglayicilar();

        $this->isle(OngorusmeKayitIsleyici::class, ['on_gorusme_id' => $this->onGorusme()]);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('bildirimler')->count());
    }

    public function test_ongorusme_metinler_neden_adi_push_ve_panel_yolu(): void
    {
        $this->saglayicilar();
        $this->ayar(12);
        DB::table('urunler')->insert(['id' => 80, 'urun_adi' => 'Krem']);

        $this->isle(OngorusmeKayitIsleyici::class, ['on_gorusme_id' => $this->onGorusme(['urun_id' => 80])]);

        $personel = 'Can Demir isimli müşterinin 19.05.2099 - 10:00 Krem için ön görüşme randevusu Veli Yetkili tarafından düzenlenmiştir.';
        $this->assertSame([
            ['to' => self::MUSTERI_TEL, 'message' => 'Salon A tarafından 19.05.2099 - 10:00 olarak ön görüşme randevunuz düzenlenmiştir. Randevunuza 15 dk önce gelmenizi rica ederiz. Detaylı bilgi için bize ulaşın. 02321112233'],
            ['to' => self::PERSONEL_TEL, 'message' => $personel],
        ], $this->smsler());
        $this->assertSame(1, DB::table('bildirimler')->whereNull('baslik')->where('personel_id', 30)->where('aciklama', $personel)->where('img_src', 'r.png')->count());
        $push = DB::table('bildirimler')->where('baslik', 'Yeni Ön Görüşme Randevusu')->first();
        $this->assertSame("appointment_detail?randevu_id={$this->randevuId}&on_gorusme=1", $push->deep_link);
        $this->assertSame('{"ongorusme":"1"}', $push->extra_data);
    }

    public function test_ongorusme_yetkili_yoksa_isletme_neden_yoksa_on_gorusme_yazilir(): void
    {
        $this->saglayicilar();
        $this->ayar(12, 0, 1);

        $this->isle(OngorusmeKayitIsleyici::class, ['on_gorusme_id' => $this->onGorusme()], null);

        $this->assertSame('Can Demir isimli müşterinin 19.05.2099 - 10:00 Ön Görüşme için ön görüşme randevusu işletme tarafından düzenlenmiştir.', $this->smsler()[0]['message']);
    }

    public function test_ongorusme_sms_hatasi_yutulmaz_yeniden_denenir(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $this->ayar(12);

        $this->expectException(RuntimeException::class);
        $this->isle(OngorusmeKayitIsleyici::class, ['on_gorusme_id' => $this->onGorusme()]);
    }
}
