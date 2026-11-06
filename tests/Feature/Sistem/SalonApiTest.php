<?php

namespace Tests\Feature\Sistem;

use App\Models\SistemYoneticisi;
use App\Services\Deneme\DemoHesapKurServisi;
use App\Services\Oturum\PlatformSms;
use App\Services\SistemYonetim\SistemBildirimServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Salon uclari: rol kapisi, destek kapsami, uyelik kurallari, denetim izi. Izole sqlite. */
class SalonApiTest extends TestCase
{
    private const KOK = '/api/v2/sistem/salon';

    private int $benimSalon;

    private int $baskaSalon;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sy_salon_sqlite',
            'database.connections.sy_salon_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'cache.default' => 'array',
            'sistem.yazma_aktif' => true,
        ]);
        DB::purge('sy_salon_sqlite');
        DB::setDefaultConnection('sy_salon_sqlite');
        $this->semaKur();

        $this->benimSalon = $this->salonEkle(['salon_adi' => 'Benim', 'musteri_yetkili_id' => 3]);
        $this->baskaSalon = $this->salonEkle(['salon_adi' => 'Baska', 'musteri_yetkili_id' => 99]);
    }

    private function semaKur(): void
    {
        Schema::create('sistemyoneticileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'password', 'rol'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('admin')->default(0);
            $t->timestamps();
        });
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            foreach (['salon_adi', 'domain', 'adres', 'telefon_1', 'telefon_2', 'telefon_3', 'yetkili_adi', 'yetkili_telefon', 'yetkili_mail', 'aciklama',
                'android_uygulama', 'ios_uygulama', 'huawei_uygulama', 'android_son_versiyon', 'ios_son_versiyon', 'huawei_son_versiyon', 'askiya_alma_sebebi', 'sms_secret'] as $k) {
                $t->string($k)->nullable();
            }
            foreach (['il_id', 'ilce_id', 'salon_turu_id', 'musteri_yetkili_id', 'uyelik_turu', 'demo_hesabi', 'askiya_alan_user_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->integer('askiya_alindi')->default(0);
            $t->integer('whatsapp_aktif')->default(0);
            $t->date('uyelik_bitis_tarihi')->nullable();
            $t->timestamp('askiya_alma_tarihi')->nullable();
            $t->timestamps();
        });
        Schema::create('isletmeyetkilileri', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'email', 'gsm1', 'gsm2', 'telefon'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('salon_id')->nullable();
            $t->integer('is_admin')->nullable();
            $t->timestamps();
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('yetkili_id')->nullable();
            $t->integer('role_id')->nullable();
            $t->integer('aktif')->default(1);
            $t->string('personel_adi')->nullable();
            $t->string('cep_telefon')->nullable();
            $t->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->integer('role_id');
            $t->string('model_type');
            $t->integer('model_id');
            $t->integer('salon_id');
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('randevular', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'cep_telefon', 'email', 'cinsiyet', 'dogum_tarihi'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('whatsapp_onay')->default(1);
        });
        Schema::create('musteri_portfoy', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('user_id');
            $t->integer('musteri_tipi')->nullable();
            $t->integer('kara_liste')->nullable();
            $t->integer('aktif')->default(1);
            $t->timestamps();
        });
        Schema::create('sistemyonetim_salon_notlari', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('user_id');
            $t->string('user_name');
            $t->string('baslik')->nullable();
            $t->text('icerik');
            $t->string('tip')->default('genel');
            $t->integer('pinned')->default(0);
            $t->timestamps();
        });
        Schema::create('sistemyonetim_destek_talepleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id')->nullable();
            foreach (['numara', 'konu', 'durum', 'oncelik'] as $k) {
                $t->string($k)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('sistemyonetim_impersonation_loglari', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->string('user_name')->nullable();
            $t->string('sebep')->nullable();
            $t->timestamp('baslangic_tarihi')->nullable();
        });
        Schema::create('sistemyonetim_audit_log', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            foreach (['user_name', 'user_rol', 'action', 'target_type', 'target_label', 'ip', 'user_agent'] as $k) {
                $t->string($k)->nullable();
            }
            $t->integer('target_id')->nullable();
            $t->text('aciklama')->nullable();
            $t->text('meta')->nullable();
            $t->timestamps();
        });
        Schema::create('salon_uyelik_odemeleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            foreach (['paket', 'periyot', 'yapan_adi', 'aciklama'] as $k) {
                $t->string($k)->nullable();
            }
            foreach (['adet', 'hediye_ay', 'toplam_ay', 'kdv_dahil', 'yapan_id'] as $k) {
                $t->integer($k)->default(0);
            }
            $t->decimal('ucret', 12, 2)->default(0);
            $t->date('eski_tarih')->nullable();
            $t->date('yeni_tarih')->nullable();
            $t->timestamps();
        });
        foreach (['il' => 'il_adi', 'salon_turu' => 'salon_turu_adi'] as $tablo => $kolon) {
            Schema::create($tablo, function (Blueprint $t) use ($kolon) {
                $t->id();
                $t->string($kolon);
            });
        }
        Schema::create('ilce', function (Blueprint $t) {
            $t->id();
            $t->integer('il_id');
            $t->string('ilce_adi');
        });
    }

    private function salonEkle(array $alan = []): int
    {
        return DB::table('salonlar')->insertGetId($alan + ['salon_adi' => 'Salon', 'adres' => '', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function giris(string $rol, int $id = 3): self
    {
        $yonetici = SistemYoneticisi::query()->firstOrCreate(['id' => $id], ['name' => 'Y'.$id, 'email' => "y{$id}@t.tr", 'password' => 'x', 'rol' => $rol]);
        $yonetici->update(['rol' => $rol]);

        return $this->actingAs($yonetici, 'sistemyonetim-api');
    }

    private function denetimVar(string $islem): bool
    {
        return DB::table('sistemyonetim_audit_log')->where('action', $islem)->exists();
    }

    public function test_destek_yalniz_atandigi_salonu_gorur(): void
    {
        $this->giris('destek');

        $liste = $this->getJson(self::KOK)->assertOk()->json('veri');
        $this->assertSame([$this->benimSalon], array_column($liste['data'], 'id'));

        $this->getJson(self::KOK.'/'.$this->benimSalon)->assertOk();
        $this->getJson(self::KOK.'/'.$this->baskaSalon)->assertForbidden();
    }

    public function test_demo_tanimi_demo_hesabi_uyelik_turu_degil(): void
    {
        $premium = $this->salonEkle(['uyelik_turu' => 3, 'demo_hesabi' => 0, 'uyelik_bitis_tarihi' => now()->addDays(10)->toDateString()]);
        $demo = $this->salonEkle(['uyelik_turu' => 1, 'demo_hesabi' => 1, 'uyelik_bitis_tarihi' => now()->addDays(10)->toDateString()]);
        $this->giris('yonetici');

        $v = $this->getJson(self::KOK.'?tip=demo')->assertOk()->json('veri');
        $this->assertSame([$demo], array_column($v['data'], 'id'));
        $this->assertSame(1, $v['sayilar']['demo']);

        $aktifler = array_column($this->getJson(self::KOK.'?tip=aktif')->json('veri.data'), 'id');
        $this->assertContains($premium, $aktifler);
        $this->assertNotContains($demo, $aktifler);
    }

    public function test_arama_joker_karakterleri_kacar(): void
    {
        $this->giris('yonetici');

        $this->assertSame(0, $this->getJson(self::KOK.'?ara=%25')->json('veri.total'));
    }

    public function test_detay_sirlari_cikarmaz_son_giris_salon_personelinden(): void
    {
        DB::table('salonlar')->where('id', $this->benimSalon)->update(['sms_secret' => 'gizli']);
        $yetkili = DB::table('isletmeyetkilileri')->insertGetId(['name' => 'Ayse', 'updated_at' => now()->subDays(2)]);
        DB::table('salon_personelleri')->insert(['salon_id' => $this->benimSalon, 'yetkili_id' => $yetkili, 'aktif' => 0]);
        $this->giris('izleyici');

        $v = $this->getJson(self::KOK.'/'.$this->benimSalon)->assertOk()->json('veri');

        $this->assertArrayNotHasKey('sms_secret', $v['salon']);
        $this->assertNotNull($v['saglik']['sinyaller']['son_giris']);
        $this->assertNotContains('Hesaba 90+ gündür giriş yok', $v['saglik']['sebepler']);
        $this->assertFalse($v['yetkililer'][0]['aktif']);
    }

    public function test_yazma_rol_kapilari(): void
    {
        $sure = self::KOK.'/'.$this->benimSalon.'/sure-uzat';

        $this->giris('izleyici');
        $this->postJson(self::KOK.'/'.$this->benimSalon.'/not', ['icerik' => 'not'])->assertForbidden();
        $this->postJson($sure, ['gun' => 7])->assertForbidden();

        $this->giris('destek');
        $this->postJson($sure, ['gun' => 7])->assertForbidden();
        $this->getJson(self::KOK.'/csv')->assertForbidden();

        $this->giris('yonetici');
        $this->postJson(self::KOK.'/'.$this->benimSalon.'/sahip-yap', ['yetkili_id' => 1])->assertForbidden();
    }

    public function test_destek_kapsami_not_uclarinda_da_gecerli(): void
    {
        $not = DB::table('sistemyonetim_salon_notlari')->insertGetId(['salon_id' => $this->baskaSalon, 'user_id' => 3, 'user_name' => 'Y', 'icerik' => 'x']);
        $this->giris('destek');

        $this->postJson(self::KOK.'/'.$this->baskaSalon.'/not', ['icerik' => 'deneme'])->assertForbidden();
        $this->postJson(self::KOK."/not/{$not}/pin")->assertForbidden();
        $this->deleteJson(self::KOK."/not/{$not}")->assertForbidden();

        $this->postJson(self::KOK.'/'.$this->benimSalon.'/not', ['icerik' => 'deneme', 'tip' => 'sikayet'])->assertOk();
        $this->assertTrue($this->denetimVar('not_ekle'));
    }

    public function test_yalniz_kendi_notu_silinir(): void
    {
        $not = DB::table('sistemyonetim_salon_notlari')->insertGetId(['salon_id' => $this->benimSalon, 'user_id' => 42, 'user_name' => 'Y', 'icerik' => 'x']);

        $this->giris('yonetici');
        $this->deleteJson(self::KOK."/not/{$not}")->assertForbidden();

        $this->giris('super_admin', 4);
        $this->deleteJson(self::KOK."/not/{$not}")->assertOk();
        $this->assertTrue($this->denetimVar('not_sil'));
    }

    public function test_sure_uzatma_kalan_sureyi_korur_ve_loglar(): void
    {
        $ileri = now()->addDays(10)->toDateString();
        DB::table('salonlar')->where('id', $this->benimSalon)->update(['uyelik_bitis_tarihi' => $ileri]);
        $this->giris('yonetici');

        $this->postJson(self::KOK.'/'.$this->benimSalon.'/sure-uzat', ['gun' => 7])->assertOk();

        $this->assertSame(now()->addDays(17)->toDateString(), DB::table('salonlar')->where('id', $this->benimSalon)->value('uyelik_bitis_tarihi'));
        $this->assertTrue($this->denetimVar('salon_sure_uzat'));
        $this->postJson(self::KOK.'/'.$this->benimSalon.'/sure-uzat', [])->assertStatus(422);
        $this->postJson(self::KOK.'/'.$this->benimSalon.'/sure-uzat', ['tarih' => 'yarin'])->assertStatus(422);
    }

    public function test_paketli_uzatma_tahsilat_yazar_ve_yetkiliyi_bilgilendirir(): void
    {
        DB::table('salonlar')->where('id', $this->benimSalon)->update(['demo_hesabi' => 1, 'uyelik_turu' => 3, 'yetkili_telefon' => '5321112233']);
        $bildirim = Mockery::mock(SistemBildirimServisi::class);
        $bildirim->shouldReceive('gonderTekil')->once()->withArgs(fn ($tel, $mesaj) => $tel === '5321112233' && str_contains($mesaj, 'Süre: 16 ay (4 ay hediye dahil)') && str_contains($mesaj, 'KDV dahil 1.500,50'))
            ->andReturn(['ok' => true, 'wa' => true, 'sms' => true]);
        $this->app->instance(SistemBildirimServisi::class, $bildirim);
        $this->giris('yonetici');

        $this->postJson(self::KOK.'/'.$this->benimSalon.'/paketli-uzat', ['paket' => 'Standart', 'periyot' => 'yillik', 'adet' => 1, 'hediye_ay' => 4, 'ucret' => 1500.5, 'kdv_dahil' => 1])
            ->assertOk()->assertJsonPath('veri.bildirim', 'gönderildi');

        $salon = DB::table('salonlar')->where('id', $this->benimSalon)->first();
        $this->assertSame(0, (int) $salon->demo_hesabi);
        $this->assertSame(2, (int) $salon->uyelik_turu);
        $this->assertSame(now()->addMonths(16)->toDateString(), $salon->uyelik_bitis_tarihi);
        $odeme = DB::table('salon_uyelik_odemeleri')->first();
        $this->assertSame(16, (int) $odeme->toplam_ay);
        $this->assertSame(1, (int) $odeme->kdv_dahil);
        $this->assertTrue($this->denetimVar('salon_paketli_uzat'));

        $this->postJson(self::KOK.'/'.$this->benimSalon.'/paketli-uzat', ['paket' => 'Altin', 'periyot' => 'yillik', 'adet' => 1])->assertStatus(422);
    }

    public function test_bildirim_hatasi_kaydi_geri_almaz(): void
    {
        DB::table('salonlar')->where('id', $this->benimSalon)->update(['yetkili_telefon' => '5321112233']);
        $bildirim = Mockery::mock(SistemBildirimServisi::class);
        $bildirim->shouldReceive('gonderTekil')->andThrow(new \RuntimeException('kopru yok'));
        $this->app->instance(SistemBildirimServisi::class, $bildirim);
        $this->giris('yonetici');

        $this->postJson(self::KOK.'/'.$this->benimSalon.'/paketli-uzat', ['paket' => 'Premium', 'periyot' => 'aylik', 'adet' => 1])
            ->assertOk()->assertJsonPath('veri.bildirim', 'hata');
        $this->assertSame(1, DB::table('salon_uyelik_odemeleri')->count());
    }

    public function test_bilgi_guncelleme_gelmeyen_alana_dokunmaz(): void
    {
        DB::table('salonlar')->where('id', $this->benimSalon)->update(['telefon_1' => '111', 'aciklama' => 'eski']);
        $this->giris('yonetici');

        $this->putJson(self::KOK.'/'.$this->benimSalon.'/bilgi', ['aciklama' => ''])->assertOk();

        $salon = DB::table('salonlar')->where('id', $this->benimSalon)->first();
        $this->assertSame('111', $salon->telefon_1);
        $this->assertNull($salon->aciklama);
        $this->putJson(self::KOK.'/'.$this->benimSalon.'/bilgi', ['salon_adi' => ''])->assertStatus(422);
    }

    public function test_ilce_secilen_ile_ait_olmali(): void
    {
        $il = DB::table('il')->insertGetId(['il_adi' => 'A']);
        $baskaIl = DB::table('il')->insertGetId(['il_adi' => 'B']);
        $ilce = DB::table('ilce')->insertGetId(['il_id' => $baskaIl, 'ilce_adi' => 'C']);
        $this->giris('yonetici');

        $this->putJson(self::KOK.'/'.$this->benimSalon.'/bilgi', ['il_id' => $il, 'ilce_id' => $ilce])->assertStatus(422);
        $this->putJson(self::KOK.'/'.$this->benimSalon.'/bilgi', ['il_id' => $baskaIl, 'ilce_id' => $ilce])->assertOk();
    }

    public function test_toplu_islem_ve_tasinmayan_sil(): void
    {
        $this->giris('super_admin');

        $this->postJson(self::KOK.'/toplu-islem', ['ids' => [$this->benimSalon, $this->baskaSalon], 'islem' => 'askiya_al'])->assertOk();
        $this->assertSame(2, DB::table('salonlar')->where('askiya_alindi', 1)->where('askiya_alma_sebebi', 'Toplu askıya alma')->count());
        $this->assertTrue($this->denetimVar('toplu_askiya_al'));

        $this->postJson(self::KOK.'/toplu-islem', ['ids' => [$this->benimSalon], 'islem' => 'sil'])->assertStatus(422);
        $this->assertSame(2, DB::table('salonlar')->count());
        $this->postJson(self::KOK.'/toplu-islem', ['islem' => 'aktif_et'])->assertStatus(422);
    }

    public function test_sahip_yap_baska_salonun_hesabini_reddeder(): void
    {
        $yetkili = DB::table('isletmeyetkilileri')->insertGetId(['name' => 'Ayse', 'is_admin' => 0]);
        DB::table('salon_personelleri')->insert(['salon_id' => $this->benimSalon, 'yetkili_id' => $yetkili, 'role_id' => 5]);
        DB::table('model_has_roles')->insert(['role_id' => 5, 'model_type' => 'App\\IsletmeYetkilileri', 'model_id' => $yetkili, 'salon_id' => $this->benimSalon]);
        $this->giris('super_admin');

        $this->postJson(self::KOK.'/'.$this->baskaSalon.'/sahip-yap', ['yetkili_id' => $yetkili])->assertStatus(422);
        $this->postJson(self::KOK.'/'.$this->benimSalon.'/sahip-yap', ['yetkili_id' => $yetkili])->assertOk();

        $this->assertSame(1, (int) DB::table('salon_personelleri')->where('yetkili_id', $yetkili)->value('role_id'));
        $this->assertSame(1, (int) DB::table('isletmeyetkilileri')->where('id', $yetkili)->value('is_admin'));
        $this->assertSame([1], DB::table('model_has_roles')->where('model_id', $yetkili)->pluck('role_id')->map(fn ($r) => (int) $r)->all());
    }

    public function test_hesap_pasif_ve_geri_al(): void
    {
        $yetkili = DB::table('isletmeyetkilileri')->insertGetId(['name' => 'Ayse']);
        DB::table('salon_personelleri')->insert(['salon_id' => $this->benimSalon, 'yetkili_id' => $yetkili, 'aktif' => 1]);
        $this->giris('yonetici');

        $this->postJson(self::KOK.'/'.$this->benimSalon.'/hesap-pasif', ['yetkili_id' => $yetkili, 'aktif' => 0])->assertOk();
        $this->assertSame(0, (int) DB::table('salon_personelleri')->where('yetkili_id', $yetkili)->value('aktif'));
        $this->assertTrue($this->denetimVar('salon_hesap_pasif'));

        $this->postJson(self::KOK.'/'.$this->baskaSalon.'/hesap-pasif', ['yetkili_id' => $yetkili, 'aktif' => 1])->assertStatus(422);
    }

    public function test_musteri_csv_telefonu_metin_tutar_formulu_etkisizlestirir(): void
    {
        $user = DB::table('users')->insertGetId(['name' => '=HYPERLINK("x")', 'cep_telefon' => '5321112233']);
        DB::table('musteri_portfoy')->insert(['salon_id' => $this->benimSalon, 'user_id' => $user]);
        $this->giris('yonetici');

        $icerik = $this->get(self::KOK.'/'.$this->benimSalon.'/musteriler/csv')->assertOk()->streamedContent();

        $this->assertStringContainsString("\xE2\x80\x8B5321112233", $icerik);
        $this->assertStringContainsString("'=HYPERLINK", $icerik);
        $this->assertTrue($this->denetimVar('excel_export'));
    }

    public function test_demo_salon_secenekleri_kuruluma_gecer_sifre_sms_ile_gider(): void
    {
        $tur = DB::table('salon_turu')->insertGetId(['salon_turu_adi' => 'Kuafor']);
        $salon = $this->salonEkle(['uyelik_bitis_tarihi' => '2026-12-31']);
        $kurulum = Mockery::mock(DemoHesapKurServisi::class);
        $kurulum->shouldReceive('kur')->once()->withArgs(fn ($g, $s) => $g['cep_telefon'] === '5321112233' && $s['demo_gun'] == 30 && $s['sifre'] === 'gizli123')
            ->andReturn(['yetkili_id' => 1, 'salon_id' => $salon, 'sifre' => 'gizli123']);
        $this->app->instance(DemoHesapKurServisi::class, $kurulum);
        $sms = Mockery::mock(PlatformSms::class);
        $sms->shouldReceive('gonder')->once()->withArgs(fn ($tel, $mesaj) => $tel === '5321112233' && str_contains($mesaj, 'gizli123'))->andReturn(true);
        $this->app->instance(PlatformSms::class, $sms);
        $this->giris('yonetici');

        $this->postJson(self::KOK, ['salon_adi' => 'Yeni', 'salon_turu_id' => $tur, 'yetkili_ad' => 'Ayse', 'yetkili_telefon' => '0532 111 22 33', 'yetkili_email' => 'a@b.tr', 'yetkili_sifre' => 'gizli123', 'demo_gun' => 30])
            ->assertCreated()->assertJsonPath('veri.sifre_gonderildi', true)->assertJsonPath('veri.demo_bitis', '2026-12-31');
        $this->assertTrue($this->denetimVar('salon_demo_olustur'));
    }

    public function test_ayni_telefonla_demo_acilmaz(): void
    {
        $tur = DB::table('salon_turu')->insertGetId(['salon_turu_adi' => 'Kuafor']);
        DB::table('isletmeyetkilileri')->insert(['name' => 'Var', 'gsm1' => '5321112233']);
        $this->giris('yonetici');

        $this->postJson(self::KOK, ['salon_adi' => 'Yeni', 'salon_turu_id' => $tur, 'yetkili_ad' => 'Ayse', 'yetkili_telefon' => '05321112233', 'yetkili_email' => 'a@b.tr'])->assertStatus(409);
    }
}
