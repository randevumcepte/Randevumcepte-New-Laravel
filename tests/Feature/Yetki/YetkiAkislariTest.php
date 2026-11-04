<?php

namespace Tests\Feature\Yetki;

use App\Models\IsletmeYetkilileri;
use App\Models\PersonelYetkiAyari;
use App\Services\CagriMerkezi\AramaFiltreSorgusu;
use App\Services\Santral\SantralIstemcisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Personel yetki akislari uc uca (HTTP): cagri merkezi rol kapilari + liste/randevu/ses kapsami,
 * telefon maskesi, sekreter rapor kapilari, yetki kaydetme. Izole sqlite — paylasilan veritabanina
 * dokunmaz; santral sahtedir.
 */
class YetkiAkislariTest extends TestCase
{
    private const SALON = 100;

    private const PAKETSIZ_SALON = 200;

    // Yetkili id'leri
    private const SAHIP = 1;

    private const ROL2 = 2;

    private const ROL3 = 3;

    private const SEKRETER = 4;

    private const AYSE = 11;   // rol 5, dahili 101, liste 501

    private const BURAK = 12;  // rol 5, dahili 102, liste 502

    private const TEL_KENDI = '05321234567';

    private const TEL_BASKA = '05559876543';

    private const SES_KENDI = '/r/out-1-20261103-101500-kendi.wav';

    private const SES_BASKA = '/r/out-1-20261103-091500-baska.wav';

    /** Santrale son sorulan dahililer. */
    private array $sorulanDahililer = [];

    private bool $santralHata = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'yetki_akis_sqlite',
            'database.connections.yetki_akis_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'cagri_merkezi.yazma_aktif' => true,
            'personel.yazma_aktif' => true,
        ]);
        DB::purge('yetki_akis_sqlite');
        DB::setDefaultConnection('yetki_akis_sqlite');

        $this->semaKur();
        $this->veriKur();
        $this->santralKur();
    }

    private function semaKur(): void
    {
        $tablolar = [
            'isletmeyetkilileri' => ['name'],
            'salonlar' => ['salon_adi', 'uyelik_turu', 'studyo_modu'],
            'salon_personelleri' => ['yetkili_id', 'salon_id', 'role_id', 'personel_adi', 'dahili_no', 'dahili_no_webrtc', 'aktif'],
            'model_has_roles' => ['role_id', 'model_type', 'model_id', 'salon_id'],
            'personel_yetki_ayarlari' => ['personel_id', 'salon_id', 'sablon', 'ayarlar', 'created_at', 'updated_at'],
            'users' => ['name', 'cep_telefon', 'cinsiyet'],
            'musteri_portfoy' => ['salon_id', 'user_id', 'aktif', 'created_at'],
            'arama_listesi' => ['salon_id', 'arama_baslik', 'personel_id', 'aranacak_tarih', 'durum', 'olusturan_yetkili_id', 'filtre_snapshot', 'created_at', 'updated_at'],
            'aranacak_musteriler' => ['arama_id', 'user_id', 'durum', 'randevu_olusturuldu', 'musteri_not', 'tarih', 'saat', 'ses_kaydi', 'ar_tamamlandi_at', 'ar_gecikti', 'created_at', 'updated_at'],
            'gorusme_notlari' => ['salon_id', 'user_id', 'aranacak_musteri_id', 'sonuc', 'not', 'ses_kaydi', 'satis_tutari', 'created_at'],
            'cagri_scriptleri' => ['salon_id', 'baslik', 'icerik', 'aktif', 'created_at', 'updated_at'],
            'cagri_kategorileri' => ['salon_id', 'ust_id', 'ad', 'sira', 'aktif', 'created_at', 'updated_at'],
            'sabit_numaralar' => ['salon_id', 'numara'],
            'v2_bildirim_kuyrugu' => ['salon_id', 'tur', 'randevu_id', 'yetkili_id', 'payload', 'durum', 'deneme', 'created_at', 'updated_at'],
        ];
        foreach ($tablolar as $ad => $kolonlar) {
            Schema::create($ad, function (Blueprint $t) use ($kolonlar) {
                $t->bigIncrements('id');
                foreach ($kolonlar as $k) {
                    preg_match('/_id$|^durum$|^aktif$|^sira$|^sonuc$|^deneme$|^ar_gecikti$|^randevu_olusturuldu$|^uyelik_turu$|^studyo_modu$/', $k)
                        ? $t->integer($k)->nullable()
                        : $t->text($k)->nullable();
                }
            });
        }
    }

    private function veriKur(): void
    {
        DB::table('salonlar')->insert([
            ['id' => self::SALON, 'salon_adi' => 'Premium', 'uyelik_turu' => 3, 'studyo_modu' => 0],
            ['id' => self::PAKETSIZ_SALON, 'salon_adi' => 'Basit', 'uyelik_turu' => 1, 'studyo_modu' => 0],
        ]);
        foreach ([
            [self::SAHIP, 1, null], [self::ROL2, 2, null], [self::ROL3, 3, null], [self::SEKRETER, 4, '104'],
            [self::AYSE, 5, '101'], [self::BURAK, 5, '102'],
        ] as [$yetkili, $rol, $dahili]) {
            $this->hesapKur($yetkili, $rol, self::SALON, $dahili);
        }
        $this->hesapKur(50, 3, self::PAKETSIZ_SALON, null);

        DB::table('users')->insert([
            ['id' => 900, 'name' => 'Kendi Musteri', 'cep_telefon' => self::TEL_KENDI],
            ['id' => 901, 'name' => 'Baska Musteri', 'cep_telefon' => self::TEL_BASKA],
        ]);
        DB::table('arama_listesi')->insert([
            ['id' => 501, 'salon_id' => self::SALON, 'arama_baslik' => 'Ayse listesi', 'personel_id' => self::AYSE * 10, 'durum' => 1],
            ['id' => 502, 'salon_id' => self::SALON, 'arama_baslik' => 'Burak listesi', 'personel_id' => self::BURAK * 10, 'durum' => 1],
        ]);
        $yarin = now()->addDay()->toDateString();
        DB::table('aranacak_musteriler')->insert([
            ['id' => 601, 'arama_id' => 501, 'user_id' => 900, 'durum' => null, 'tarih' => null, 'saat' => null, 'ses_kaydi' => self::SES_BASKA],
            ['id' => 602, 'arama_id' => 502, 'user_id' => 901, 'durum' => null, 'tarih' => null, 'saat' => null, 'ses_kaydi' => null],
            ['id' => 603, 'arama_id' => 501, 'user_id' => 900, 'durum' => 3, 'tarih' => $yarin, 'saat' => '10:00:00', 'ses_kaydi' => null],
            ['id' => 604, 'arama_id' => 502, 'user_id' => 901, 'durum' => 3, 'tarih' => $yarin, 'saat' => '11:00:00', 'ses_kaydi' => null],
        ]);
    }

    /** Personel id = yetkili id x 10. */
    private function hesapKur(int $yetkili, int $rol, int $salon, ?string $dahili): void
    {
        DB::table('isletmeyetkilileri')->insert(['id' => $yetkili, 'name' => "Yetkili $yetkili"]);
        DB::table('salon_personelleri')->insert(['id' => $yetkili * 10, 'yetkili_id' => $yetkili, 'salon_id' => $salon, 'role_id' => $rol,
            'personel_adi' => "Personel $yetkili", 'dahili_no' => $dahili, 'aktif' => 1]);
        DB::table('model_has_roles')->insert(['role_id' => $rol, 'model_type' => 'isletme', 'model_id' => $yetkili, 'salon_id' => $salon]);
    }

    /** Sahte santral: her ses kaydi bir dahiliye ait; yalniz sorulan dahililerin kayitlari doner. */
    private function santralKur(): void
    {
        $santral = Mockery::mock(SantralIstemcisi::class);
        $santral->shouldReceive('cdrListesi')->andReturnUsing(function (array $dahililer) {
            $this->sorulanDahililer = $dahililer;
            if ($this->santralHata) {
                return ['satirlar' => [], 'hata' => true];
            }
            $kayitlar = [
                ['dahili' => '101', 'dcontext' => 'ext-local', 'dst' => '101', 'recording_path' => self::SES_KENDI, 'src' => self::TEL_KENDI, 'calldate' => '2026-11-03 10:15:00'],
                ['dahili' => '102', 'dcontext' => 'ext-local', 'dst' => '102', 'recording_path' => self::SES_BASKA, 'src' => self::TEL_KENDI, 'calldate' => '2026-11-03 09:15:00'],
            ];

            return ['satirlar' => array_values(array_filter($kayitlar, fn ($k) => in_array($k['dahili'], $dahililer, true))), 'hata' => false];
        });
        $this->app->instance(SantralIstemcisi::class, $santral);
    }

    private function giris(int $yetkili): self
    {
        // Karar motoru istek basi cache tutar; testte tek uygulama ornegi yasadigi icin her giriste tazelenir.
        $this->app->forgetInstance(YetkiServisi::class);
        $this->app->forgetInstance(GuvenlikKapisi::class);

        return $this->actingAs(IsletmeYetkilileri::find($yetkili), 'isletmeyonetim-api');
    }

    private function url(string $yol, int $salon = self::SALON): string
    {
        return '/api/v2'.$yol.(str_contains($yol, '?') ? '&' : '?').'salon_id='.$salon;
    }

    private function ozelKayit(int $yetkili, array $ayarlar, string $sablon = 'ozel'): void
    {
        PersonelYetkiAyari::create(['personel_id' => $yetkili * 10, 'salon_id' => self::SALON, 'sablon' => $sablon, 'ayarlar' => $ayarlar]);
    }

    // ------------------------------------------------------------------
    // Cagri merkezi: sekmeler ve kapilar
    // ------------------------------------------------------------------

    public function test_personel_yalniz_calisma_sekmelerini_gorur(): void
    {
        $veri = $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/acilis'))->assertOk()->json('veri');

        $this->assertSame(['calisma', 'ajanda', 'takvim', 'kayitlar'], array_column($veri['sekmeler'], 'kod'));
        $this->assertNull($veri['liste_filtreleri']);
        $this->assertFalse($veri['erisim']['yonetici']);
        $this->assertSame([self::AYSE * 10], array_column($veri['personeller'], 'id'));
    }

    /** @return array<string, array{0:int}> */
    public static function yoneticiRolleri(): array
    {
        return ['sahip' => [self::SAHIP], 'rol 2' => [self::ROL2], 'rol 3' => [self::ROL3], 'sekreter' => [self::SEKRETER]];
    }

    #[DataProvider('yoneticiRolleri')]
    public function test_yonetici_tum_sekmeleri_gorur(int $yetkili): void
    {
        $veri = $this->giris($yetkili)->getJson($this->url('/cagri-merkezi/acilis'))->assertOk()->json('veri');

        $this->assertSame(['calisma', 'ajanda', 'takvim', 'kayitlar', 'performans', 'listeler', 'ayarlar'], array_column($veri['sekmeler'], 'kod'));
        $this->assertNotNull($veri['liste_filtreleri']);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function personeleKapaliUclar(): array
    {
        return [
            'ayar oku'        => ['GET', '/cagri-merkezi/ayarlar'],
            'script ekle'     => ['POST', '/cagri-merkezi/scriptler'],
            'script duzenle'  => ['PATCH', '/cagri-merkezi/scriptler/1'],
            'script sil'      => ['DELETE', '/cagri-merkezi/scriptler/1'],
            'kategori ekle'   => ['POST', '/cagri-merkezi/kategoriler'],
            'kategori duzenle' => ['PATCH', '/cagri-merkezi/kategoriler/1'],
            'kategori sil'    => ['DELETE', '/cagri-merkezi/kategoriler/1'],
            'liste onizleme'  => ['POST', '/cagri-merkezi/listeler/onizleme'],
            'liste olustur'   => ['POST', '/cagri-merkezi/listeler'],
            'segmentler'      => ['GET', '/cagri-merkezi/listeler/501/segmentler'],
            'csv'             => ['GET', '/cagri-merkezi/listeler/501/csv?kod=bekleyen'],
            'liste durum'     => ['PATCH', '/cagri-merkezi/listeler/501/durum'],
            'liste personel'  => ['PATCH', '/cagri-merkezi/listeler/501/personel'],
            'liste tasi'      => ['POST', '/cagri-merkezi/listeler/501/tasi'],
            'liste sil'       => ['DELETE', '/cagri-merkezi/listeler/501'],
            'performans'      => ['GET', '/cagri-merkezi/performans'],
            'performans detay' => ['GET', '/cagri-merkezi/performans/110'],
        ];
    }

    #[DataProvider('personeleKapaliUclar')]
    public function test_personel_ayar_liste_ve_performans_uclarina_giremez(string $metot, string $yol): void
    {
        // Kendi listesi (501) uzerinde bile: kural rol kapisidir, kayit kapsami degil. Govde bos: dogrulamadan once 403.
        $this->giris(self::AYSE)->json($metot, $this->url($yol))
            ->assertForbidden()
            ->assertJsonPath('basarili', false)
            ->assertJsonPath('mesaj', 'Bu bölüm personel hesaplarına kapalıdır');

        $this->assertSame(2, DB::table('arama_listesi')->count());
    }

    #[DataProvider('yoneticiRolleri')]
    public function test_yonetici_ayar_ve_liste_yonetimini_kullanir(int $yetkili): void
    {
        $this->giris($yetkili)->getJson($this->url('/cagri-merkezi/ayarlar'))->assertOk();
        $this->giris($yetkili)->getJson($this->url('/cagri-merkezi/listeler/502/segmentler'))->assertOk()->assertJsonPath('veri.id', 502);
        $this->giris($yetkili)->postJson($this->url('/cagri-merkezi/scriptler'), ['baslik' => 'Karsilama'])->assertOk();

        $this->assertSame(1, DB::table('cagri_scriptleri')->count());
    }

    public function test_paket_disi_ve_baska_salon_kapali(): void
    {
        $this->giris(50)->getJson($this->url('/cagri-merkezi/acilis', self::PAKETSIZ_SALON))->assertForbidden();
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/acilis', self::PAKETSIZ_SALON))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Cagri merkezi: personelin veri kapsami
    // ------------------------------------------------------------------

    public function test_personel_yalniz_kendine_atanan_listeleri_gorur(): void
    {
        $kartlar = $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/listeler'))->assertOk()->json('veri.kartlar');
        $this->assertSame([501], array_column($kartlar, 'id'));

        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/listeler/501/musteriler'))->assertOk();
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/listeler/502/musteriler'))->assertForbidden();
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk();
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/602'))->assertForbidden();
    }

    public function test_yonetici_tum_listeleri_gorur(): void
    {
        $kartlar = $this->giris(self::SEKRETER)->getJson($this->url('/cagri-merkezi/listeler'))->assertOk()->json('veri.kartlar');

        $this->assertEqualsCanonicalizing([501, 502], array_column($kartlar, 'id'));
    }

    public function test_personel_yalniz_kendi_arama_randevularini_gorur(): void
    {
        $olaylar = $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/takvim'))->assertOk()->json('veri.olaylar');
        $this->assertSame([603], array_column($olaylar, 'id'));

        $olaylar = $this->giris(self::SAHIP)->getJson($this->url('/cagri-merkezi/takvim'))->assertOk()->json('veri.olaylar');
        $this->assertEqualsCanonicalizing([603, 604], array_column($olaylar, 'id'));
    }

    public function test_personel_baskasinin_kaydini_isleyemez(): void
    {
        $this->giris(self::AYSE)->postJson($this->url('/cagri-merkezi/kayit/602/iptal'))->assertForbidden();
        $this->giris(self::AYSE)->postJson($this->url('/cagri-merkezi/kayit/602/sonuc'), ['sonuc' => 0])->assertForbidden();

        $this->assertNull(DB::table('aranacak_musteriler')->where('id', 602)->value('durum'));
    }

    public function test_personel_cagri_kayitlarinda_yalniz_kendi_dahilisini_sorgular(): void
    {
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/cagri-kayitlari'))->assertOk();
        $this->assertSame(['101'], $this->sorulanDahililer);

        $this->giris(self::SAHIP)->getJson($this->url('/cagri-merkezi/cagri-kayitlari'))->assertOk();
        $this->assertEqualsCanonicalizing(['101', '102', '104'], $this->sorulanDahililer);
    }

    public function test_musteri_ses_kayitlarinda_personel_yalniz_kendininkini_gorur(): void
    {
        $personel = $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/601/ses-kayitlari'))->assertOk()->getContent();
        $this->assertStringContainsString('kendi.wav', $personel);
        $this->assertStringNotContainsString('baska.wav', $personel);

        $yonetici = $this->giris(self::SAHIP)->getJson($this->url('/cagri-merkezi/kayit/601/ses-kayitlari'))->assertOk()->getContent();
        $this->assertStringContainsString('kendi.wav', $yonetici);
        $this->assertStringContainsString('baska.wav', $yonetici);
    }

    public function test_gorusme_gecmisinde_personel_baskasinin_sesini_goremez(): void
    {
        DB::table('gorusme_notlari')->insert(['salon_id' => self::SALON, 'user_id' => 900, 'aranacak_musteri_id' => 601,
            'sonuc' => 2, 'not' => 'Onceki gorusme', 'ses_kaydi' => self::SES_BASKA, 'created_at' => '2026-11-03 09:16:00']);

        $personel = $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/601/gecmis'))->assertOk();
        $this->assertStringNotContainsString('baska.wav', $personel->getContent());
        $this->assertStringContainsString('kendi.wav', $personel->getContent());
        $this->assertStringContainsString('Onceki gorusme', $personel->getContent());

        $this->assertStringContainsString('baska.wav',
            $this->giris(self::SAHIP)->getJson($this->url('/cagri-merkezi/kayit/601/gecmis'))->assertOk()->getContent());
    }

    public function test_santral_erisilemezse_personele_ses_donmez_yoneticide_kayitli_ses_kalir(): void
    {
        $this->santralHata = true;
        DB::table('gorusme_notlari')->insert(['salon_id' => self::SALON, 'user_id' => 900, 'aranacak_musteri_id' => 601,
            'sonuc' => 2, 'not' => 'Not', 'ses_kaydi' => self::SES_BASKA, 'created_at' => '2026-11-03 09:16:00']);

        $personel = $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/601/gecmis'))->assertOk();
        $personel->assertJsonPath('veri.santral_hata', true);
        $this->assertStringNotContainsString('baska.wav', $personel->getContent());

        $this->assertStringContainsString('baska.wav',
            $this->giris(self::SAHIP)->getJson($this->url('/cagri-merkezi/kayit/601/gecmis'))->assertOk()->getContent());
    }

    public function test_kuyruk_satirinda_personele_ses_baglantisi_gitmez(): void
    {
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->assertJsonPath('veri.ses', '');
        $this->assertStringContainsString('baska.wav',
            $this->giris(self::SAHIP)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->json('veri.ses'));
    }

    // ------------------------------------------------------------------
    // Telefon maskesi (karar 1)
    // ------------------------------------------------------------------

    public function test_tabloda_kaydi_olmayan_yonetici_telefonu_gorur(): void
    {
        foreach ([self::ROL2, self::ROL3, self::SEKRETER] as $yetkili) {
            $this->giris($yetkili)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()
                ->assertJsonPath('veri.telefon', self::TEL_KENDI)
                ->assertJsonPath('veri.ad', 'Kendi Musteri');
        }
    }

    public function test_tabloda_ayari_kapali_yonetici_telefonu_goremez(): void
    {
        $this->ozelKayit(self::ROL3, ['musteri.telefon_gor' => false], 'yonetici');

        $yanit = $this->giris(self::ROL3)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->assertJsonPath('veri.telefon', null);
        $this->assertStringNotContainsString(self::TEL_KENDI, $yanit->getContent());
        $this->assertSame('Kendi M.', $yanit->json('veri.ad'));
        $this->giris(self::ROL3)->getJson($this->url('/cagri-merkezi/acilis'))->assertJsonPath('veri.erisim.telefon_gor', false);
    }

    public function test_tabloda_ayari_acik_yonetici_telefonu_gorur(): void
    {
        $this->ozelKayit(self::SEKRETER, ['musteri.telefon_gor' => true], 'yonetici');

        $this->giris(self::SEKRETER)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->assertJsonPath('veri.telefon', self::TEL_KENDI);
    }

    public function test_yonetici_sablonu_varsayilani_telefonu_acar(): void
    {
        $this->ozelKayit(self::ROL3, ['randevu.olustur' => true], 'yonetici');

        $this->giris(self::ROL3)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->assertJsonPath('veri.telefon', self::TEL_KENDI);
    }

    public function test_hesap_sahibi_kapali_kayitla_bile_telefonu_gorur(): void
    {
        $this->ozelKayit(self::SAHIP, ['musteri.telefon_gor' => false]);

        $this->giris(self::SAHIP)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->assertJsonPath('veri.telefon', self::TEL_KENDI);
    }

    public function test_personel_varsayilanda_maskeli_ayar_acilirsa_gorur(): void
    {
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->assertJsonPath('veri.telefon', null);

        $this->ozelKayit(self::AYSE, ['musteri.telefon_gor' => true]);
        $this->giris(self::AYSE)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertOk()->assertJsonPath('veri.telefon', self::TEL_KENDI);
    }

    public function test_csv_anahtari_izler(): void
    {
        $this->assertStringContainsString(self::TEL_KENDI,
            $this->giris(self::ROL3)->get($this->url('/cagri-merkezi/listeler/501/csv?kod=bekleyen'))->assertOk()->getContent());

        $this->ozelKayit(self::ROL2, ['musteri.telefon_gor' => false]);
        $this->assertStringNotContainsString(self::TEL_KENDI,
            $this->giris(self::ROL2)->get($this->url('/cagri-merkezi/listeler/501/csv?kod=bekleyen'))->assertOk()->getContent());
    }

    public function test_liste_onizleme_anahtari_izler(): void
    {
        DB::table('musteri_portfoy')->insert(['salon_id' => self::SALON, 'user_id' => 900, 'aktif' => 1]);
        $sorgu = Mockery::mock(AramaFiltreSorgusu::class);
        $sorgu->shouldReceive('say')->andReturn(1);
        $sorgu->shouldReceive('sorgu')->andReturnUsing(fn () => DB::table('musteri_portfoy as mp')->join('users as u', 'u.id', '=', 'mp.user_id'));
        $this->app->instance(AramaFiltreSorgusu::class, $sorgu);

        $this->giris(self::ROL3)->postJson($this->url('/cagri-merkezi/listeler/onizleme'))->assertOk()
            ->assertJsonPath('veri.data.0.telefon', self::TEL_KENDI);

        $this->ozelKayit(self::ROL2, ['musteri.telefon_gor' => false]);
        $yanit = $this->giris(self::ROL2)->postJson($this->url('/cagri-merkezi/listeler/onizleme'))->assertOk();
        $this->assertStringNotContainsString(self::TEL_KENDI, $yanit->getContent());
        $this->assertSame('Kendi M.', $yanit->json('veri.data.0.ad'));
    }

    // ------------------------------------------------------------------
    // Sekreter rapor kapilari (karar 2) + yetki ekrani
    // ------------------------------------------------------------------

    /** @return array<string, array{0:string}> */
    public static function sekretereKapaliRaporlar(): array
    {
        return [
            'kasa ozet'        => ['/kasa/ozet'],
            'kasa gelirler'    => ['/kasa/gelirler'],
            'kasa gun sonu'    => ['/kasa/gun-sonu'],
            'kasa aylik'       => ['/kasa/aylik'],
            'satis raporu'     => ['/rapor/satis'],
            'satis personel'   => ['/rapor/satis/personel'],
            'isletme ozet'     => ['/rapor/isletme/ozet'],
            'isletme hizmet'   => ['/rapor/isletme/hizmet'],
            'isletme urun'     => ['/rapor/isletme/urun'],
            'isletme personel' => ['/rapor/isletme/personel'],
            'isletme musteri'  => ['/rapor/isletme/musteri'],
            'isletme randevu'  => ['/rapor/isletme/randevu'],
        ];
    }

    #[DataProvider('sekretereKapaliRaporlar')]
    public function test_kayitsiz_sekreter_kasa_satis_isletme_raporlarina_giremez(string $yol): void
    {
        $this->giris(self::SEKRETER)->getJson($this->url($yol))->assertForbidden()->assertJsonPath('mesaj', 'Bu işlem için yetkiniz yok');
    }

    public function test_benim_yetkilerim_sekreter_ve_yonetici(): void
    {
        $sekreter = $this->giris(self::SEKRETER)->getJson($this->url('/personel-yetki/benim'))->assertOk()
            ->assertJsonPath('veri.sablon', 'sekreter_varsayilan')->json('veri.ayarlar');
        $this->assertFalse($sekreter['rapor.kasa']);
        $this->assertTrue($sekreter['randevu.olustur']);
        $this->assertTrue($sekreter['musteri.telefon_gor']);

        $rol3 = $this->giris(self::ROL3)->getJson($this->url('/personel-yetki/benim'))->assertOk()
            ->assertJsonPath('veri.sablon', 'tam_yetki')->json('veri.ayarlar');
        $this->assertTrue($rol3['rapor.kasa']);
        $this->assertFalse($rol3['personel.kapali_adisyon_gizle']);
    }

    public function test_kullanici_kendi_yetkisini_uctan_acamaz(): void
    {
        $this->giris(self::SEKRETER)->patchJson('/api/v2/personel/'.(self::SEKRETER * 10).'/yetki', ['sablon' => 'ozel', 'ayarlar' => ['rapor.kasa' => true]])
            ->assertForbidden()->assertJsonPath('mesaj', 'Kendi yetkilerinizi değiştiremezsiniz');

        $this->assertSame(0, DB::table('personel_yetki_ayarlari')->count());
        $this->giris(self::SEKRETER)->getJson($this->url('/kasa/ozet'))->assertForbidden();
    }

    public function test_yetkisi_olmayan_baskasinin_yetkisini_degistiremez(): void
    {
        $this->giris(self::AYSE)->patchJson('/api/v2/personel/'.(self::BURAK * 10).'/yetki', ['sablon' => 'yonetici', 'ayarlar' => []])->assertForbidden();

        $this->assertSame(0, DB::table('personel_yetki_ayarlari')->count());
    }

    public function test_sahip_kaydedince_kural_hemen_uygulanir(): void
    {
        $hedef = self::ROL3 * 10;
        $this->giris(self::ROL3)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertJsonPath('veri.telefon', self::TEL_KENDI);

        $this->giris(self::SAHIP)->patchJson("/api/v2/personel/$hedef/yetki", ['sablon' => 'ozel', 'ayarlar' => ['musteri.telefon_gor' => false]])->assertOk();

        $this->giris(self::ROL3)->getJson($this->url('/cagri-merkezi/kayit/601'))->assertJsonPath('veri.telefon', null);
        $this->assertSame('yetki_degisti', DB::table('v2_bildirim_kuyrugu')->value('tur'));
        $ayarlar = $this->giris(self::SAHIP)->getJson("/api/v2/personel/$hedef/yetki")->assertOk()
            ->assertJsonPath('veri.kayit_var', true)->json('veri.ayarlar');
        $this->assertFalse($ayarlar['musteri.telefon_gor']);
    }

    public function test_kimliksiz_istek_reddedilir(): void
    {
        $this->getJson($this->url('/cagri-merkezi/acilis'))->assertUnauthorized();
    }
}
