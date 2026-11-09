<?php

namespace Tests\Feature\Yetki;

use App\Domain\Oturum\TokenTipi;
use App\Exceptions\CakismaException;
use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\OnayKoduGerekliException;
use App\Models\Randevular;
use App\Services\Oturum\MobilBayraklar;
use App\Services\Oturum\MobilKodGirisi;
use App\Services\Randevu\KvkkOnayKodu;
use App\Services\Randevu\MusteriDogrulamaKodu;
use App\Services\Yetki\HesapSahibiOnayi;
use App\Services\Yetki\YetkiServisi;
use App\Support\OnayKodu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Iki adimli kodlu islemler: SMS ayari 23 (silmede hesap sahibi onayi) ve 16 (seans geldi musteri kodu).
 * Kod ilk istekte gider (WhatsApp oncelikli, acil: kuyruk 'sms' dogrulama=true), 428 doner; yanlis kod 428
 * (yeni kod gitmez); dogru kod bir kez gecer. Izole sqlite.
 */
class KodluIslemTest extends TestCase
{
    private const SALON = 100;

    private const SAHIP = 1;

    private const PERSONEL = 2;

    private const SAHIP_TEL = '5321112233';

    private const MUSTERI_TEL = '5329998877';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'oturum.onay_kodu' => ['bekleme_sn' => 60, 'gunluk_kod' => 5, 'gunluk_hata' => 10, 'randevu_hata' => 5, 'randevu_hata_sn' => 600],
            'database.default' => 'kodlu_sqlite',
            'database.connections.kodlu_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('kodlu_sqlite');
        DB::setDefaultConnection('kodlu_sqlite');

        $tablolar = [
            'salon_sms_ayarlari' => ['salon_id', 'ayar_id', 'musteri', 'personel'],
            'salon_personelleri' => ['yetkili_id', 'salon_id', 'role_id', 'aktif', 'personel_adi'],
            'model_has_roles' => ['role_id', 'model_type', 'model_id', 'salon_id'],
            'isletmeyetkilileri' => ['gsm1'],
            'users' => ['cep_telefon'],
            'tahsilatlar' => ['odeme_tarihi', 'tutar', 'adisyon_id'],
            'adisyonlar' => ['tarih'],
            'adisyon_hizmetler' => ['adisyon_id', 'hizmet_id', 'fiyat'],
            'adisyon_urunler' => ['adisyon_id', 'urun_id', 'fiyat'],
            'adisyon_paketler' => ['adisyon_id', 'paket_id', 'fiyat'],
            'hizmetler' => ['hizmet_adi'],
            'urunler' => ['urun_adi'],
            'paketler' => ['paket_adi'],
            'v2_bildirim_kuyrugu' => ['salon_id', 'tur', 'randevu_id', 'yetkili_id', 'payload', 'durum', 'deneme', 'created_at', 'updated_at'],
            'randevular' => ['salon_id', 'user_id', 'dogrulama', 'randevuya_geldi', 'created_at', 'updated_at'],
            'musteri_portfoy' => ['user_id', 'salon_id', 'onay_kodu', 'kvkk_onay_alindi'],
        ];
        foreach ($tablolar as $ad => $kolonlar) {
            Schema::create($ad, function (Blueprint $t) use ($kolonlar) {
                $t->id();
                foreach ($kolonlar as $k) {
                    $t->text($k)->nullable();
                }
            });
        }

        DB::table('isletmeyetkilileri')->insert([['id' => self::SAHIP, 'gsm1' => self::SAHIP_TEL], ['id' => self::PERSONEL, 'gsm1' => '5320000000']]);
        foreach ([[self::SAHIP, 1, 'Sahip'], [self::PERSONEL, 5, 'Ayse']] as [$yetkili, $rol, $ad]) {
            DB::table('salon_personelleri')->insert(['yetkili_id' => $yetkili, 'salon_id' => self::SALON, 'role_id' => $rol, 'aktif' => 1, 'personel_adi' => $ad]);
            DB::table('model_has_roles')->insert(['role_id' => $rol, 'model_type' => 'isletme', 'model_id' => $yetkili, 'salon_id' => self::SALON]);
        }
        DB::table('tahsilatlar')->insert(['id' => 50, 'odeme_tarihi' => '2026-11-01 10:00:00', 'tutar' => 1250.5, 'adisyon_id' => 60]);
        DB::table('adisyonlar')->insert(['id' => 60, 'tarih' => '2026-11-02']);
        DB::table('hizmetler')->insert(['id' => 3, 'hizmet_adi' => 'Lazer']);
        DB::table('adisyon_hizmetler')->insert(['id' => 70, 'adisyon_id' => 60, 'hizmet_id' => 3, 'fiyat' => 900]);
        DB::table('users')->insert(['id' => 80, 'cep_telefon' => self::MUSTERI_TEL]);
        DB::table('randevular')->insert(['id' => 90, 'salon_id' => self::SALON, 'user_id' => 80]);
        Cache::flush();
    }

    private function ayar(int $ayarId, string $kolon): void
    {
        DB::table('salon_sms_ayarlari')->insert(['salon_id' => self::SALON, 'ayar_id' => $ayarId, $kolon => 1]);
    }

    private function onay(): HesapSahibiOnayi
    {
        return new HesapSahibiOnayi(new YetkiServisi());
    }

    /** Islem 428 ile durur; kuyruktaki son mesaj ve kod doner. */
    private function kodIstendi(callable $islem): array
    {
        try {
            $islem();
            $this->fail('428 bekleniyordu');
        } catch (OnayKoduGerekliException $e) {
            $this->assertSame(428, $e->durumKodu);
            $this->assertTrue($e->kodGonderildi);
        }
        $satir = DB::table('v2_bildirim_kuyrugu')->orderByDesc('id')->first();
        $payload = json_decode($satir->payload, true);
        $this->assertSame('sms', $satir->tur);
        $this->assertTrue($payload['dogrulama'], 'kod mesaji acil / WhatsApp oncelikli gitmeli');
        preg_match('/(\d{4})$/', $payload['mesajlar'][0]['message'], $m);

        return [$payload['mesajlar'][0], $m[1]];
    }

    public function test_ayar_kapaliyken_ya_da_hesap_sahibi_silerken_kod_istenmez(): void
    {
        $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, null);
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        $this->onay()->tahsilatSilme(self::SALON, self::SAHIP, 50, null);

        $this->assertSame(0, DB::table('v2_bildirim_kuyrugu')->count());
        $this->assertFalse($this->onay()->gerekli(self::SALON, self::SAHIP));
        $this->assertTrue($this->onay()->gerekli(self::SALON, self::PERSONEL));
    }

    public function test_personel_tahsilat_silerken_hesap_sahibine_kod_gider_kod_tek_kullanimlik(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        [$mesaj, $kod] = $this->kodIstendi(fn () => $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, null));

        $this->assertSame(self::SAHIP_TEL, $mesaj['to']);
        $this->assertSame('Ayse 01.11.2026 tarihli 1.250,50 ₺ tutarındaki tahsilatı silmek için onayınızı istiyor. Onay Kodu : '.$kod, $mesaj['message']);

        // Baska kayit icin ayni kod gecmez; yanlis kodda yeni kod gitmez.
        try {
            $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 51, $kod);
            $this->fail('baska kayitta kod gecmemeli');
        } catch (OnayKoduGerekliException $e) {
            $this->assertFalse($e->kodGonderildi);
        }
        $this->assertSame(1, DB::table('v2_bildirim_kuyrugu')->count());

        $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, $kod);
        $this->expectException(OnayKoduGerekliException::class);
        $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, $kod); // tekrar kullanilamaz
    }

    public function test_kalem_ve_adisyon_silme_ayni_kapidan(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        [$mesaj, $kod] = $this->kodIstendi(fn () => $this->onay()->kalemSilme(self::SALON, self::PERSONEL, 'hizmet', 70, ''));
        $this->assertStringContainsString('02.11.2026 tarihli satıştan 900,00 ₺ tutarındaki Lazer hizmetini silmek', $mesaj['message']);
        $this->onay()->kalemSilme(self::SALON, self::PERSONEL, 'hizmet', 70, $kod);

        [$mesaj, $kod] = $this->kodIstendi(fn () => $this->onay()->adisyonSilme(self::SALON, self::PERSONEL, 60, null));
        $this->assertStringContainsString('900,00 ₺ tutarındaki satışı (adisyon #60) silmek', $mesaj['message']);
        $this->onay()->adisyonSilme(self::SALON, self::PERSONEL, 60, $kod);
    }

    public function test_tahsilat_azaltma_ortak_kapi_tutar_dusurme_ve_odeme_geri_alma(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        // Tutar artiyorsa / ayniysa onay yok.
        $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 1250.5, null);
        $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 2000.0, null);
        $this->assertSame(0, DB::table('v2_bildirim_kuyrugu')->count());

        [$mesaj, $kod] = $this->kodIstendi(fn () => $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 1000.0, null));
        $this->assertStringContainsString('1.250,50 ₺ tutarındaki tahsilatı 1.000,00 ₺ tutarına düşürmek', $mesaj['message']);
        try {
            $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 1.0, $kod);
            $this->fail('kod onaylanan tutara bagli');
        } catch (OnayKoduGerekliException $e) {
            $this->assertFalse($e->kodGonderildi);
        }
        $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 1000.0, $kod);

        // Komisyon: brut artsa da net (tutar - komisyon) dusuyorsa azaltmadir; kod net tutara baglanir.
        $this->travel(61)->seconds();
        [$mesaj, $kod] = $this->kodIstendi(fn () => $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 2000.0, null, 1999.0));
        $this->assertStringContainsString('1.250,50 ₺ tutarındaki tahsilatı 1,00 ₺ tutarına düşürmek', $mesaj['message']);
        try {
            $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 2000.0, $kod, 1999.5);
            $this->fail('kod onaylanan net tutara bagli');
        } catch (OnayKoduGerekliException $e) {
            $this->assertFalse($e->kodGonderildi);
        }
        $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 2000.0, $kod, 1999.0);
        $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 1300.5, null, 50.0); // net ayni: onay yok

        // Studyo "odeme alinmadi": adisyonun tahsilatlari silinir, ayni kapi; hesap sahibine kod gitmez.
        $this->onay()->odemeGeriAlma(self::SALON, self::SAHIP, 60, null);
        $this->onay()->odemeGeriAlma(self::SALON, self::PERSONEL, 61, null); // tahsilati yok
        $this->travel(61)->seconds();
        [$mesaj, $kod] = $this->kodIstendi(fn () => $this->onay()->odemeGeriAlma(self::SALON, self::PERSONEL, 60, null));
        $this->assertStringContainsString('adisyon #60 için alınan 1.250,50 ₺ tutarındaki ödemeyi geri almak', $mesaj['message']);
        $this->onay()->odemeGeriAlma(self::SALON, self::PERSONEL, 60, $kod);

        // Mobil token: kod girisi yok → 409.
        $this->mobilIstek();
        $this->expectException(CakismaException::class);
        $this->onay()->tahsilatAzaltma(self::SALON, self::PERSONEL, 50, 10.0, null);
    }

    public function test_hesap_sahibi_telefonu_yoksa_islem_acik_hata_verir(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        DB::table('isletmeyetkilileri')->where('id', self::SAHIP)->update(['gsm1' => '']);

        $this->expectExceptionMessage('Hesap sahibinin telefonu kayıtlı değil');
        $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, null);
    }

    public function test_428_cevabi_web_ve_mobil_icin_ayni_zarf(): void
    {
        $cevap = (new OnayKoduGerekliException('Kod girin', false))->render();

        $this->assertSame(428, $cevap->getStatusCode());
        $this->assertSame(['basarili' => false, 'veri' => ['dogrulama_gerekli' => true, 'kod_gonderildi' => false], 'mesaj' => 'Kod girin'], $cevap->getData(true));
    }

    public function test_seans_geldi_ayar_16_aciksa_musteriye_kod_gider(): void
    {
        $servis = new MusteriDogrulamaKodu();
        $servis->seansGeldi(self::SALON, 80, ['seans' => 5], null);
        $this->assertSame(0, DB::table('v2_bildirim_kuyrugu')->count(), 'ayar kapaliyken kod istenmez');

        $this->ayar(MusteriDogrulamaKodu::SMS_AYARI, 'musteri');
        [$mesaj, $kod] = $this->kodIstendi(fn () => $servis->seansGeldi(self::SALON, 80, ['seans' => 5], null));
        $this->assertSame(self::MUSTERI_TEL, $mesaj['to']);
        $this->assertSame(MusteriDogrulamaKodu::mesaj($kod), $mesaj['message']);

        $servis->seansGeldi(self::SALON, 80, ['seans' => 5], $kod);
        $this->expectException(OnayKoduGerekliException::class);
        $servis->seansGeldi(self::SALON, 80, ['seans' => 5], $kod);
    }

    public function test_seans_geldi_musteri_telefonu_yoksa_hata(): void
    {
        $this->ayar(MusteriDogrulamaKodu::SMS_AYARI, 'musteri');
        DB::table('users')->where('id', 80)->update(['cep_telefon' => null]);

        $this->expectExceptionMessage('Müşterinin telefonu kayıtlı değil');
        (new MusteriDogrulamaKodu())->seansGeldi(self::SALON, 80, ['seans' => 5], null);
    }

    // ───────── Kaba kuvvet ve mesaj bombardimani (OnayKodu + KodGonderimSiniri) ─────────

    private function sil(?string $kod): void
    {
        $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, $kod);
    }

    public function test_bekleme_suresinde_yeni_kod_uretilmez_428_kalan_sure(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        [, $kod] = $this->kodIstendi(fn () => $this->sil(null));

        try {
            $this->sil(null);
            $this->fail('bekleme suresinde 428 bekleniyordu');
        } catch (OnayKoduGerekliException $e) {
            $this->assertFalse($e->kodGonderildi);
            $this->assertMatchesRegularExpression('/^Kod gönderildi\. Yeni kod için \d+ sn sonra tekrar deneyin\.$/', $e->getMessage());
        }
        $this->assertSame(1, DB::table('v2_bildirim_kuyrugu')->count(), 'bekleme suresinde mesaj gitmez');

        $this->sil($kod); // onceki kod gecerli kalir
        $this->addToAssertionCount(1);
    }

    public function test_bekleme_bitince_yeni_kod_gunde_en_cok_bes(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        foreach (range(1, 5) as $i) {
            $this->kodIstendi(fn () => $this->sil(null));
            $this->travel(61)->seconds();
        }

        try {
            $this->sil(null);
            $this->fail('altinci kod reddedilmeliydi');
        } catch (CokFazlaIstekException $e) {
            $this->assertSame(429, $e->durumKodu);
            $this->assertStringContainsString('son 24 saatte çok fazla kod istendi', $e->getMessage());
        }
        $this->assertSame(5, DB::table('v2_bildirim_kuyrugu')->count());
    }

    public function test_gunluk_yanlis_siniri_yeni_kodla_sifirlanmaz(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        // Her kodda 4 yanlis (kod basina 5 siniri dolmadan), yeni kod: toplam 10 yanlista gun kapanir.
        $yanlis = 0;
        while ($yanlis < 10) {
            [, $kod] = $this->kodIstendi(fn () => $this->sil(null));
            for ($i = 0; $i < 4 && $yanlis < 10; $i++, $yanlis++) {
                try {
                    $this->sil($kod === '0000' ? '0001' : '0000');
                } catch (OnayKoduGerekliException) {
                }
            }
            $this->travel(61)->seconds();
            if ($yanlis < 10) {
                Cache::forget('v2_kod_gun:v2_onay_kodu:hesap_sahibi_silme:'.self::SALON.':'.self::PERSONEL); // gunluk kod siniri bu testin konusu degil
            }
        }

        $this->expectException(CokFazlaIstekException::class);
        $this->expectExceptionMessage('Son 24 saatte çok fazla hatalı kod girildi');
        $this->sil(null);
    }

    public function test_bekleme_islem_basina_baska_kayitta_yalniz_gunluk_sinir(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        $this->kodIstendi(fn () => $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, null));
        // Baska kayit (adisyon 60) icin bekleme yok: hemen yeni kod gider (onceki kodun yerini alir).
        [, $kod] = $this->kodIstendi(fn () => $this->onay()->adisyonSilme(self::SALON, self::PERSONEL, 60, null));
        // Ilk kaydin kodu artik yok: onun beklemesinde kalinmaz, yeni kod gider.
        [, $kod50] = $this->kodIstendi(fn () => $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, null));
        $this->assertSame(3, DB::table('v2_bildirim_kuyrugu')->count());

        // Ayni kayit icin bekleme surer.
        try {
            $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, null);
            $this->fail('ayni kayitta bekleme 428 vermeli');
        } catch (OnayKoduGerekliException $e) {
            $this->assertFalse($e->kodGonderildi);
            $this->assertStringContainsString('sn sonra tekrar', $e->getMessage());
        }
        $this->onay()->tahsilatSilme(self::SALON, self::PERSONEL, 50, $kod50);
        $this->addToAssertionCount(1);
    }

    public function test_baska_islemin_kodu_yanlis_sayilmaz_ve_tuketilmez(): void
    {
        config(['oturum.onay_kodu.gunluk_hata' => 2]);
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        [, $kod] = $this->kodIstendi(fn () => $this->sil(null));

        foreach (range(1, 3) as $i) {
            try {
                $this->onay()->adisyonSilme(self::SALON, self::PERSONEL, 60, $kod);
                $this->fail('baska islemin kodu gecmemeli');
            } catch (OnayKoduGerekliException $e) {
                $this->assertSame(OnayKodu::BASKA_ISLEM_MESAJI, $e->getMessage());
                $this->assertFalse($e->kodGonderildi);
            }
        }
        $this->sil($kod); // gunluk yanlis siniri (2) dolmadi, kod tuketilmedi
        $this->addToAssertionCount(1);

        // Saf kural: eski bicimli kayit (kod/baglam izi yok) baska islem sayilmaz.
        $this->assertFalse(OnayKodu::baskaIslemIcin(['hash' => 'x'], '1234', 'b'));
        $kayit = ['kod' => hash('sha256', '1234|t'), 'baglam' => 'a', 'tek' => 't'];
        $this->assertTrue(OnayKodu::baskaIslemIcin($kayit, '1234', 'b'));
        $this->assertFalse(OnayKodu::baskaIslemIcin($kayit, '1234', 'a'), 'ayni baglam: hash yolu karar verir');
        $this->assertFalse(OnayKodu::baskaIslemIcin($kayit, '9999', 'b'), 'yanlis kod');
    }

    public function test_gonderilemeyen_kodda_kod_iptal_bekleme_kalkar(): void
    {
        foreach (range(1, 2) as $i) {
            try {
                OnayKodu::uretVeGonder('kasa_cikis', self::SALON, self::PERSONEL, ['tutar' => 10.0], function () {
                    throw new GecersizIslemException('Onay kodu gönderilecek yetkili telefonu bulunamadı');
                });
                $this->fail('gonderim hatasi yukari gitmeli');
            } catch (GecersizIslemException $e) {
                $this->assertStringContainsString('telefonu bulunamadı', $e->getMessage());
            }
        }
        $this->assertFalse(OnayKodu::bekliyorMu('kasa_cikis', self::SALON, self::PERSONEL), 'kod silinir');
        $gonderilen = null;
        OnayKodu::uretVeGonder('kasa_cikis', self::SALON, self::PERSONEL, ['tutar' => 10.0], function (string $k) use (&$gonderilen) {
            $gonderilen = $k;
        });
        $this->assertNotNull($gonderilen, 'bekleme kalktigi icin hemen yeni kod');
        $this->assertTrue(OnayKodu::dogrulaSinirli('kasa_cikis', self::SALON, self::PERSONEL, $gonderilen, ['tutar' => 10.0]));
    }

    public function test_dogru_kod_yalniz_bir_kez_tuketilir(): void
    {
        $kod = OnayKodu::uret('test', self::SALON, 1, ['k' => 1]);

        $this->assertTrue(OnayKodu::dogrula('test', self::SALON, 1, $kod, ['k' => 1]));
        $this->assertFalse(OnayKodu::dogrula('test', self::SALON, 1, $kod, ['k' => 1]));
    }

    // ───────── Mobilde kod girisi yok: kod uretilmez, 409 ─────────

    private function mobilIstek(): void
    {
        $istek = Request::create('/api/v2/test', 'DELETE');
        MobilBayraklar::uygula($istek, [TokenTipi::ISLETME, TokenTipi::MOBIL]);
        $this->app->instance('request', $istek);
    }

    public function test_mobil_tokende_kod_gerekiyorsa_kod_uretilmez_409(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        $this->ayar(MusteriDogrulamaKodu::SMS_AYARI, 'musteri');
        $this->mobilIstek();

        foreach ([
            fn () => $this->sil(null),
            fn () => $this->onay()->adisyonSilme(self::SALON, self::PERSONEL, 60, null),
            fn () => (new MusteriDogrulamaKodu())->seansGeldi(self::SALON, 80, ['seans' => 5], null),
        ] as $islem) {
            try {
                $islem();
                $this->fail('409 bekleniyordu');
            } catch (CakismaException $e) {
                $this->assertSame(409, $e->durumKodu);
                $this->assertSame(MobilKodGirisi::MESAJ, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('v2_bildirim_kuyrugu')->count(), 'mesaj gonderilmez');
        $this->assertFalse(OnayKodu::bekliyorMu('hesap_sahibi_silme', self::SALON, self::PERSONEL), 'kod uretilmez');

        // Hesap sahibi kendi silmesinde kod gerekmez: mobilde de islem acik.
        $this->onay()->tahsilatSilme(self::SALON, self::SAHIP, 50, null);
    }

    // ───────── Hesap sahibinin telefonu iki kaynaktan ─────────

    public function test_hesap_sahibi_telefonu_personel_satiri_yoksa_rol_kaydindan(): void
    {
        $this->ayar(HesapSahibiOnayi::SMS_AYARI, 'personel');
        DB::table('salon_personelleri')->where('yetkili_id', self::SAHIP)->delete();

        [$mesaj] = $this->kodIstendi(fn () => $this->sil(null));

        $this->assertSame(self::SAHIP_TEL, $mesaj['to']);
        $this->assertSame(self::SAHIP_TEL, $this->onay()->sahipTelefonu(self::SALON));
        $this->assertSame('', HesapSahibiOnayi::ilkDolu([null, ' ', '']));
        $this->assertSame('532', HesapSahibiOnayi::ilkDolu(['', ' 532 ']));
    }

    // ───────── Randevu geldi kodu (randevular.dogrulama) ─────────

    public function test_randevu_geldi_kodu_tek_kullanimlik_bekleme_ve_yanlis_siniri(): void
    {
        $servis = new MusteriDogrulamaKodu();
        $gonderilen = 0;
        $gonder = function () use (&$gonderilen) {
            $gonderilen++;
            Randevular::where('id', 90)->update(['dogrulama' => '4321']); // kuyruk isleyicisinin yaptigi
        };
        $randevu = fn () => Randevular::find(90);

        $this->assertTrue($servis->randevuKoduDenetle($randevu(), '', true, $gonder)['kod_gonderildi']);
        $ikinci = $servis->randevuKoduDenetle($randevu(), null, true, $gonder);
        $this->assertFalse($ikinci['kod_gonderildi'], 'bekleme suresinde yeni kod gitmez');
        $this->assertStringContainsString('sn sonra tekrar', $ikinci['mesaj']);
        $this->assertSame(1, $gonderilen);

        // Dogru kod gecer ve tuketilir (cache kaydi yok: eski isleyicinin kodu, sure uygulanmaz).
        $this->travel(3600)->seconds();
        $this->assertNull($servis->randevuKoduDenetle($randevu(), '4321', true, $gonder), 'eski isleyici kodu: sure yok');
        $servis->randevuKoduTuket(90, '4321');
        $this->assertNull(Randevular::find(90)->dogrulama, 'basarida kolon temizlenir');

        // Tekrar kullanim yok: kolon bos, ayni kod yanlis sayilir; es zamanli ikinci tuketim reddedilir.
        $this->assertSame('2', $servis->randevuKoduDenetle($randevu(), '4321', true, $gonder)['hatali']);
        $this->expectException(GecersizIslemException::class);
        $servis->randevuKoduTuket(90, '4321');
    }

    public function test_randevu_geldi_bes_yanlista_kod_duser_429(): void
    {
        $servis = new MusteriDogrulamaKodu();
        Randevular::where('id', 90)->update(['dogrulama' => '4321']);
        $randevu = fn () => Randevular::find(90);

        // Yanlis kod: yeni kod gitmez; 5 yanlistan sonra kolon null'lanir (kod olur) ve 10 dk kilit.
        foreach (range(1, 5) as $i) {
            $this->assertSame('2', $servis->randevuKoduDenetle($randevu(), '0000', true, fn () => $this->fail('kod gitmemeli'))['hatali']);
        }
        $this->assertNull(Randevular::find(90)->dogrulama, '5 yanlista kod duser');
        try {
            $servis->randevuKoduDenetle($randevu(), '4321', true, fn () => null);
            $this->fail('yanlis siniri 429 vermeli');
        } catch (CokFazlaIstekException $e) {
            $this->assertStringContainsString('dakika sonra', $e->getMessage());
        }
        $this->travel(601)->seconds();
        $this->assertSame('2', $servis->randevuKoduDenetle($randevu(), '4321', true, fn () => null)['hatali'], 'dusen kod artik gecmez');
    }

    public function test_randevu_geldi_kodu_tek_ureticiden_10_dk_gecerli(): void
    {
        $servis = new MusteriDogrulamaKodu();
        $kod = $servis->randevuKoduUret(Randevular::find(90));
        $this->assertMatchesRegularExpression('/^\d{4}$/', $kod);
        $this->assertSame($kod, Randevular::find(90)->dogrulama, 'kolon sozlesmesi: duz 4 hane');

        $this->travel(599)->seconds();
        $this->assertNull($servis->randevuKoduDenetle(Randevular::find(90), $kod, false, fn () => null), '10 dk icinde gecer');
        $this->travel(2)->seconds();
        $c = $servis->randevuKoduDenetle(Randevular::find(90), $kod, false, fn () => null);
        $this->assertStringContainsString('süresi doldu', $c['mesaj']);
        $this->assertNull(Randevular::find(90)->dogrulama, 'suresi dolan kod duser');

        // Saf sure kurali: kayit yok / baska koda ait → eski davranis.
        $t = 1_000_000;
        $kayit = ['iz' => hash('sha256', 'randevu-geldi|1111'), 'zaman' => $t];
        $this->assertFalse(MusteriDogrulamaKodu::sureDoldu(null, '1111', $t + 9999));
        $this->assertFalse(MusteriDogrulamaKodu::sureDoldu($kayit, '2222', $t + 9999));
        $this->assertFalse(MusteriDogrulamaKodu::sureDoldu($kayit, '1111', $t + 600));
        $this->assertTrue(MusteriDogrulamaKodu::sureDoldu($kayit, '1111', $t + 601));
    }

    public function test_randevu_geldi_gunluk_yanlis_siniri(): void
    {
        config(['oturum.onay_kodu.gunluk_hata' => 3]);
        $servis = new MusteriDogrulamaKodu();
        Randevular::where('id', 90)->update(['dogrulama' => '4321']);
        foreach (range(1, 3) as $i) {
            $servis->randevuKoduDenetle(Randevular::find(90), '0000', true, fn () => null);
        }
        $this->expectException(CokFazlaIstekException::class);
        $this->expectExceptionMessage('Son 24 saatte çok fazla hatalı kod girildi');
        $servis->randevuKoduDenetle(Randevular::find(90), '', true, fn () => null);
    }

    public function test_tek_kod_uretici_rakam_tekrarina_izin_verir(): void
    {
        $tekrarli = false;
        foreach (range(1, 400) as $i) {
            $k = OnayKodu::rastgele(4);
            $this->assertMatchesRegularExpression('/^\d{4}$/', $k);
            $tekrarli = $tekrarli || count(array_unique(str_split($k))) < 4;
        }
        $this->assertTrue($tekrarli, '400 denemede tekrarli rakamli kod cikmali (olasilik ~%50/kod)');
    }

    public function test_randevu_geldi_dry_run_kod_gondermez(): void
    {
        $gonderildi = false;
        $c = (new MusteriDogrulamaKodu())->randevuKoduDenetle(Randevular::find(90), '', false, function () use (&$gonderildi) {
            $gonderildi = true;
        });

        $this->assertFalse($gonderildi);
        $this->assertSame(['hatali' => '2', 'mesaj' => MusteriDogrulamaKodu::ISTEM, 'kod_gonderildi' => false], $c);
    }

    // ───────── KVKK onay kodu (musteri_portfoy.onay_kodu) — randevu geldi koduyla ortak kapi ─────────

    private function portfoy(?string $kod): object
    {
        DB::table('musteri_portfoy')->updateOrInsert(['id' => 95], ['user_id' => 80, 'salon_id' => self::SALON, 'onay_kodu' => $kod, 'kvkk_onay_alindi' => 0]);

        return DB::table('musteri_portfoy')->where('id', 95)->first();
    }

    public function test_kvkk_bos_onay_kodu_gecilmez_gonderimde_uretilir(): void
    {
        $kvkk = new KvkkOnayKodu();
        $gonderildi = 0;
        $gonder = function () use (&$gonderildi) {
            $gonderildi++;
        };

        foreach ([null, ''] as $bos) {
            $c = $kvkk->denetle($this->portfoy($bos), '', false, $gonder);
            $this->assertSame(['hatali' => '3', 'mesaj' => KvkkOnayKodu::ISTEM, 'kod_gonderildi' => false], $c, 'bos == bos gecmez, dry-run gondermez');
            $this->assertSame('3', $kvkk->denetle($this->portfoy($bos), ' ', false, $gonder)['hatali']);
        }
        $this->assertSame(0, $gonderildi);

        $this->assertTrue($kvkk->denetle($this->portfoy(''), null, true, $gonder)['kod_gonderildi']);
        $this->assertSame(1, $gonderildi);
        $uretilen = (string) DB::table('musteri_portfoy')->where('id', 95)->value('onay_kodu');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $uretilen, 'isleyici bos kodu gonderemez: once uretilir');
        $this->assertNull($kvkk->denetle(DB::table('musteri_portfoy')->where('id', 95)->first(), $uretilen, true, $gonder));
    }

    public function test_kvkk_dogru_kod_gecer_yanlista_yeni_kod_gitmez_bekleme_ve_gunluk_kod_siniri(): void
    {
        $kvkk = new KvkkOnayKodu();
        $gonderildi = 0;
        $gonder = function () use (&$gonderildi) {
            $gonderildi++;
        };

        $c = $kvkk->denetle($this->portfoy('4321'), '1111', true, $gonder);
        $this->assertSame(['hatali' => '3', 'mesaj' => KvkkOnayKodu::HATALI, 'kod_gonderildi' => false], $c);
        $this->assertSame(0, $gonderildi, 'yanlis kodda yeniden SMS gitmez');
        $this->assertNull($kvkk->denetle($this->portfoy('4321'), ' 4321 ', true, $gonder));
        $kvkk->basarili(95);

        $this->assertTrue($kvkk->denetle($this->portfoy('4321'), '', true, $gonder)['kod_gonderildi']);
        $ikinci = $kvkk->denetle($this->portfoy('4321'), '', true, $gonder);
        $this->assertFalse($ikinci['kod_gonderildi'], 'bekleme suresinde yeniden gonderilmez');
        $this->assertStringContainsString('sn sonra tekrar', $ikinci['mesaj']);
        foreach (range(2, 5) as $i) {
            $this->travel(61)->seconds();
            $this->assertTrue($kvkk->denetle($this->portfoy('4321'), '', true, $gonder)['kod_gonderildi']);
        }
        $this->assertSame(5, $gonderildi);
        $this->travel(61)->seconds();
        $this->expectException(CokFazlaIstekException::class);
        $this->expectExceptionMessage('çok fazla kod istendi');
        $kvkk->denetle($this->portfoy('4321'), '', true, $gonder);
    }

    public function test_kvkk_yanlis_deneme_dry_run_dahil_sayilir_kod_yenilenir_429(): void
    {
        $kvkk = new KvkkOnayKodu();
        foreach (range(1, 5) as $i) {
            $this->assertSame(KvkkOnayKodu::HATALI, $kvkk->denetle($this->portfoy('4321'), '0000', false, fn () => $this->fail('kod gitmemeli'))['mesaj']);
        }
        $yeni = (string) DB::table('musteri_portfoy')->where('id', 95)->value('onay_kodu');
        $this->assertNotSame('4321', $yeni, '5 yanlista (dry-run dahil) kod yenilenir');
        try {
            $kvkk->denetle(DB::table('musteri_portfoy')->where('id', 95)->first(), $yeni, false, fn () => null);
            $this->fail('kisa pencere siniri 429 vermeli');
        } catch (CokFazlaIstekException $e) {
            $this->assertStringContainsString('dakika sonra', $e->getMessage());
        }
        $this->travel(601)->seconds();
        $this->assertSame('3', $kvkk->denetle($this->portfoy($yeni), '4321', false, fn () => null)['hatali'], 'eski kod artik gecmez');
    }

    public function test_kvkk_gunluk_yanlis_siniri(): void
    {
        config(['oturum.onay_kodu.gunluk_hata' => 3]);
        $kvkk = new KvkkOnayKodu();
        foreach (range(1, 3) as $i) {
            $kvkk->denetle($this->portfoy('4321'), '0000', false, fn () => null);
        }
        $this->expectException(CokFazlaIstekException::class);
        $this->expectExceptionMessage('Son 24 saatte çok fazla hatalı kod girildi');
        $kvkk->denetle($this->portfoy('4321'), '4321', true, fn () => null);
    }
}
