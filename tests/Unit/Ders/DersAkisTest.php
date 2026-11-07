<?php

namespace Tests\Unit\Ders;

use App\Domain\Ders\KatilimciDurumu;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Models\DersKatilimci;
use App\Models\DersOturumu;
use App\Services\Ders\DersKatilimServisi;
use App\Services\Ders\DersOturumServisi;
use App\Services\Ders\DersProgramServisi;
use App\Services\Ders\DersTekrarliServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Grup dersi is akisi: kapasite/bekleme/terfi, seans dusumu, telafi korumasi, iptal, yayin
 * idempotency, otomatik plan. Izole sqlite — PAYLASILAN PROD DB'ye DOKUNMAZ.
 * Sunucuda: php artisan test --filter=DersAkisTest
 */
class DersAkisTest extends TestCase
{
    private const SALON = 1;

    private const HIZMET = 7;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'ders_sqlite',
            'database.connections.ders_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('ders_sqlite');
        DB::setDefaultConnection('ders_sqlite');

        Schema::create('salonlar', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('studyo_modu')->default(0);
            $t->integer('grup_dersi_aktif')->default(1);
            $t->integer('musteri_online_randevu_aktif')->default(1);
        });
        Schema::create('ders_oturumlari', function (Blueprint $t) {
            $t->increments('id');
            foreach (['salon_id', 'sube_id', 'personel_id', 'hizmet_id', 'sablon_id', 'olusturan_personel_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->string('ders_tipi')->nullable();
            $t->string('tarih');
            $t->string('saat');
            $t->string('saat_bitis');
            $t->integer('kapasite')->default(1);
            $t->boolean('iptal')->default(false);
            $t->boolean('aktif')->default(true);
            $t->string('renk')->nullable();
            $t->text('not')->nullable();
            $t->timestamps();
        });
        Schema::create('ders_katilimcilar', function (Blueprint $t) {
            $t->increments('id');
            foreach (['oturum_id', 'user_id', 'salon_id', 'adisyon_paket_id', 'ekleyen_personel_id', 'aps_id', 'tekrarli_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->string('durum')->default('rezerve');
            $t->boolean('hak_dusuldu')->default(false);
            $t->text('not')->nullable();
            $t->timestamps();
        });
        Schema::create('ders_programi_sablonu', function (Blueprint $t) {
            $t->increments('id');
            foreach (['salon_id', 'sube_id', 'personel_id', 'hizmet_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->integer('hafta_gunu');
            $t->string('saat');
            $t->string('saat_bitis');
            $t->string('ders_tipi')->nullable();
            $t->integer('kapasite')->default(1);
            $t->string('renk')->nullable();
            $t->boolean('aktif')->default(true);
            $t->timestamps();
        });
        Schema::create('ders_tekrarli_katilim', function (Blueprint $t) {
            $t->increments('id');
            foreach (['salon_id', 'user_id', 'hizmet_id', 'adisyon_paket_id', 'adisyon_hizmet_id', 'personel_id'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->integer('toplam_seans')->default(0);
            $t->string('gunler')->nullable();
            $t->string('saatler')->nullable();
            $t->string('sablonlar')->nullable();
            $t->string('baslangic_tarihi')->nullable();
            $t->boolean('aktif')->default(true);
            $t->timestamps();
        });
        Schema::create('adisyon_paket_seanslar', function (Blueprint $t) {
            $t->increments('id');
            foreach (['randevu_id', 'hizmet_id', 'seans_no', 'adisyon_hizmet_id', 'adisyon_paket_id', 'personel_id', 'geldi', 'dusulen_miktar'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->string('seans_tarih')->nullable();
            $t->string('seans_saat')->nullable();
            $t->timestamps();
        });
        Schema::create('adisyonlar', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('user_id');
            $t->integer('salon_id');
            $t->string('tarih');
        });
        Schema::create('adisyon_hizmetler', function (Blueprint $t) {
            $t->increments('id');
            foreach (['adisyon_id', 'hizmet_id', 'seans_sayisi', 'kullanilan_seans', 'kullanilmayan_seans', 'otomatik_randevu_olusturuldu'] as $k) {
                $t->integer($k)->nullable();
            }
        });
        Schema::create('adisyon_paketler', function (Blueprint $t) {
            $t->increments('id');
            foreach (['adisyon_id', 'paket_id', 'seans_sayisi', 'kullanilan_seans', 'kullanilmayan_seans', 'otomatik_randevu_olusturuldu'] as $k) {
                $t->integer($k)->nullable();
            }
        });
        Schema::create('paket_hizmetler', function (Blueprint $t) {
            $t->integer('paket_id');
            $t->integer('hizmet_id');
        });
        Schema::create('paketler', function (Blueprint $t) {
            $t->increments('id');
            $t->string('paket_adi')->nullable();
        });
        Schema::create('hizmetler', function (Blueprint $t) {
            $t->increments('id');
            $t->string('hizmet_adi');
        });
        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('cep_telefon')->nullable();
        });
        Schema::create('musteri_portfoy', function (Blueprint $t) {
            $t->integer('salon_id');
            $t->integer('user_id');
        });
        Schema::create('salon_sunulan_hizmetler', function (Blueprint $t) {
            $t->integer('salon_id');
            $t->integer('hizmet_id');
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('salon_id');
        });
        Schema::create('v2_bildirim_kuyrugu', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('salon_id');
            $t->string('tur');
            $t->integer('randevu_id')->nullable();
            $t->integer('yetkili_id')->nullable();
            $t->text('payload')->nullable();
            $t->integer('durum')->default(0);
            $t->integer('deneme')->default(0);
            $t->timestamps();
        });
        Schema::create('salon_aktivite_log', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('salon_id');
        });

        DB::table('salonlar')->insert(['id' => self::SALON]);
        DB::table('hizmetler')->insert(['id' => self::HIZMET, 'hizmet_adi' => 'Reformer']);
        DB::table('salon_sunulan_hizmetler')->insert(['salon_id' => self::SALON, 'hizmet_id' => self::HIZMET]);
        foreach ([10, 11, 12] as $u) {
            DB::table('users')->insert(['id' => $u, 'name' => 'Üye '.$u, 'cep_telefon' => '555000'.$u]);
            DB::table('musteri_portfoy')->insert(['salon_id' => self::SALON, 'user_id' => $u]);
        }
    }

    private function oturum(int $kapasite = 2, ?string $tarih = null): DersOturumu
    {
        return DersOturumu::create([
            'salon_id' => self::SALON, 'hizmet_id' => self::HIZMET, 'ders_tipi' => 'Mat', 'tarih' => $tarih ?? date('Y-m-d', strtotime('+1 day')),
            'saat' => '09:00', 'saat_bitis' => '10:00', 'kapasite' => $kapasite, 'aktif' => true,
        ]);
    }

    private function seansSat(int $userId, int $seans): void
    {
        $a = DB::table('adisyonlar')->insertGetId(['user_id' => $userId, 'salon_id' => self::SALON, 'tarih' => '2026-01-01']);
        DB::table('adisyon_hizmetler')->insert(['adisyon_id' => $a, 'hizmet_id' => self::HIZMET, 'seans_sayisi' => $seans]);
    }

    private function katilim(): DersKatilimServisi
    {
        return app(DersKatilimServisi::class);
    }

    private function studyo(): void
    {
        DB::table('salonlar')->where('id', self::SALON)->update(['studyo_modu' => 1]);
    }

    public function test_kapasite_dolunca_bekleme_cikinca_terfi_ve_bildirim(): void
    {
        $o = $this->oturum(1);
        $ilk = $this->katilim()->ekle($o->id, 10, null, 5);
        $ikinci = $this->katilim()->ekle($o->id, 11, null, 5);
        $this->assertSame('rezerve', $ilk['katilimci_durum']);
        $this->assertSame('bekleme', $ikinci['katilimci_durum']);

        $this->katilim()->cikar($ilk['katilimci_id'], 5);

        $this->assertSame('iptal', DersKatilimci::find($ilk['katilimci_id'])->durum);
        $this->assertSame('rezerve', DersKatilimci::find($ikinci['katilimci_id'])->durum);
        $tipler = DB::table('v2_bildirim_kuyrugu')->where('tur', 'ders_bildirim')->pluck('payload')->map(fn ($p) => json_decode($p, true)['gonderim_tipi'])->all();
        $this->assertSame(['ders_bildirim', 'ders_bildirim', 'ders_bekleme_terfi'], $tipler);
    }

    public function test_ayni_kisi_iki_kez_eklenemez_ve_portfoy_disi_reddedilir(): void
    {
        $o = $this->oturum();
        $this->katilim()->ekle($o->id, 10, null, 5);
        $this->expectException(CakismaException::class);
        $this->katilim()->ekle($o->id, 10, null, 5);
    }

    public function test_baska_salonun_musterisi_eklenemez(): void
    {
        $o = $this->oturum();
        $this->expectException(GecersizIslemException::class);
        $this->katilim()->ekle($o->id, 999, null, 5);
    }

    public function test_geldi_iki_kez_isaretlense_de_tek_seans_duser(): void
    {
        $this->seansSat(10, 5);
        $k = $this->katilim()->ekle($this->oturum()->id, 10, null, 5);

        $this->assertSame('dusuldu', $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELDI, 5)['dusum']);
        $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELDI, 5);

        $this->assertSame(1, DB::table('adisyon_paket_seanslar')->count());
        $this->assertSame(1, (int) DB::table('adisyon_paket_seanslar')->value('geldi'));
    }

    public function test_gelmedi_normalde_iade_studyoda_seans_yakar(): void
    {
        $this->seansSat(10, 5);
        $k = $this->katilim()->ekle($this->oturum()->id, 10, null, 5);
        $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELDI, 5);

        $this->assertSame('iade', $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELMEDI, 5)['dusum']);
        $this->assertSame(0, DB::table('adisyon_paket_seanslar')->count());

        $this->studyo();
        $this->assertSame('dusuldu', $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELMEDI, 5)['dusum']);
        $this->assertSame(0, (int) DB::table('adisyon_paket_seanslar')->value('geldi'));
    }

    public function test_hak_yoksa_katilim_kaydedilir_seans_dusmez(): void
    {
        $k = $this->katilim()->ekle($this->oturum()->id, 10, null, 5);
        $this->assertSame('hak_yok', $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELDI, 5)['dusum']);
        $this->assertSame('geldi', DersKatilimci::find($k['katilimci_id'])->durum);
    }

    public function test_bekleme_satiri_isaretlenemez(): void
    {
        $o = $this->oturum(1);
        $this->katilim()->ekle($o->id, 10, null, 5);
        $b = $this->katilim()->ekle($o->id, 11, null, 5);
        $this->expectException(CakismaException::class);
        $this->katilim()->durumGuncelle($b['katilimci_id'], KatilimciDurumu::GELDI, 5);
    }

    public function test_bekleyen_telafi_yeni_derse_baglanir_cikinca_kredi_korunur(): void
    {
        $this->seansSat(10, 5);
        $eski = $this->katilim()->ekle($this->oturum(2, date('Y-m-d', strtotime('-7 day')))->id, 10, null, 5);
        $this->katilim()->durumGuncelle($eski['katilimci_id'], KatilimciDurumu::TELAFI, 5);
        $this->assertSame(2, (int) DB::table('adisyon_paket_seanslar')->value('geldi'));

        $yeni = $this->katilim()->ekle($this->oturum()->id, 10, null, 5);
        $this->assertTrue($yeni['telafi_baglandi']);
        $this->assertSame('telafi', $yeni['katilimci_durum']);
        $this->assertSame(1, DB::table('adisyon_paket_seanslar')->count(), 'yeni seans dusmez');

        $this->assertSame('telafi_korundu', $this->katilim()->cikar($yeni['katilimci_id'], 5)['dusum']);
        $this->assertSame(1, DB::table('adisyon_paket_seanslar')->where('geldi', 2)->count(), 'kredi silinmez');
        $this->assertSame('2000-01-01', DB::table('adisyon_paket_seanslar')->value('seans_tarih'));
    }

    public function test_bagli_telafi_ikinci_derse_baglanmaz_kaynak_katilimci_birakir(): void
    {
        $this->seansSat(10, 5);
        $eski = $this->katilim()->ekle($this->oturum(2, date('Y-m-d', strtotime('-7 day')))->id, 10, null, 5);
        $this->katilim()->durumGuncelle($eski['katilimci_id'], KatilimciDurumu::TELAFI, 5);

        $d2 = $this->katilim()->ekle($this->oturum(2, date('Y-m-d', strtotime('+1 day')))->id, 10, null, 5);
        $d3 = $this->katilim()->ekle($this->oturum(2, date('Y-m-d', strtotime('+8 day')))->id, 10, null, 5);

        $this->assertTrue($d2['telafi_baglandi']);
        $this->assertFalse($d3['telafi_baglandi'], 'ayni hak iki derste kullanilmaz');
        $this->assertNull(DersKatilimci::find($eski['katilimci_id'])->aps_id);

        // Bag cozulunce hak tekrar bekleyen olur ve baglanabilir.
        $this->katilim()->cikar($d2['katilimci_id'], 5);
        $d4 = $this->katilim()->ekle($this->oturum(2, date('Y-m-d', strtotime('+15 day')))->id, 10, null, 5);
        $this->assertTrue($d4['telafi_baglandi']);
    }

    public function test_danisan_isletme_isaretini_degistiremez_iptal_ders_isaretlenemez(): void
    {
        $this->seansSat(10, 5);
        $o = $this->oturum(2, date('Y-m-d'));
        $k = $this->katilim()->ekle($o->id, 10, null, 5);
        $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELDI, 5);

        try {
            $this->katilim()->danisanIsaretle($k['katilimci_id'], 10, KatilimciDurumu::GELMEDI);
            $this->fail('danisan kendine iade yapamamali');
        } catch (CakismaException) {
            $this->assertSame(1, DB::table('adisyon_paket_seanslar')->count());
        }
        $this->assertSame('geldi', $this->katilim()->danisanIsaretle($k['katilimci_id'], 10, KatilimciDurumu::GELDI)['durum'], 'cift dokunus sorun degil');

        $iptal = $this->oturum();
        $r = $this->katilim()->ekle($iptal->id, 10, null, 5);
        app(DersOturumServisi::class)->iptal($iptal->id, 5);
        $this->expectException(CakismaException::class);
        $this->katilim()->durumGuncelle($r['katilimci_id'], KatilimciDurumu::GELDI, 5);
    }

    public function test_kapasite_artinca_bekleyenler_terfi_eder_iptal_derste_etmez(): void
    {
        $o = $this->oturum(1);
        $this->katilim()->ekle($o->id, 10, null, 5);
        $b1 = $this->katilim()->ekle($o->id, 11, null, 5);
        $b2 = $this->katilim()->ekle($o->id, 12, null, 5);

        app(DersOturumServisi::class)->kaydet(self::SALON, $o->id, ['tarih' => $o->tarih, 'saat' => '09:00', 'saat_bitis' => '10:00', 'kapasite' => 2], null, 5);
        $this->assertSame('rezerve', DersKatilimci::find($b1['katilimci_id'])->durum);
        $this->assertSame('bekleme', DersKatilimci::find($b2['katilimci_id'])->durum);

        app(DersOturumServisi::class)->iptal($o->id, 5);
        $this->katilim()->terfiler([$o->id], 5);
        $this->assertSame('bekleme', DersKatilimci::find($b2['katilimci_id'])->durum);
    }

    public function test_duzenleme_gonderilmeyen_renk_ve_notu_silmez(): void
    {
        $o = $this->oturum();
        $o->update(['renk' => '#123456', 'not' => 'özel']);
        app(DersOturumServisi::class)->kaydet(self::SALON, $o->id, ['tarih' => $o->tarih, 'saat' => '09:00', 'saat_bitis' => '10:00', 'kapasite' => 5], null, 5);
        $this->assertSame('#123456', DersOturumu::find($o->id)->renk);
        $this->assertSame('özel', DersOturumu::find($o->id)->not);
    }

    public function test_ders_iptali_seansi_iade_eder_telafiyi_korur_ve_bildirir(): void
    {
        $this->seansSat(10, 5);
        $this->seansSat(11, 5);
        $o = $this->oturum();
        $a = $this->katilim()->ekle($o->id, 10, null, 5);
        $b = $this->katilim()->ekle($o->id, 11, null, 5);
        $this->katilim()->durumGuncelle($a['katilimci_id'], KatilimciDurumu::GELDI, 5);
        $this->katilim()->durumGuncelle($b['katilimci_id'], KatilimciDurumu::TELAFI, 5);

        $sonuc = app(DersOturumServisi::class)->iptal($o->id, 5);

        $this->assertSame(2, $sonuc['bildirilen']);
        $this->assertSame(1, DB::table('adisyon_paket_seanslar')->count(), 'yalniz telafi kalir');
        $this->assertSame(2, (int) DB::table('adisyon_paket_seanslar')->value('geldi'));
        $this->assertTrue(DersOturumu::find($o->id)->iptalMi());
    }

    public function test_iptal_edilmis_derse_katilimci_eklenemez(): void
    {
        $o = $this->oturum();
        app(DersOturumServisi::class)->iptal($o->id, 5);
        $this->expectException(CakismaException::class);
        $this->katilim()->ekle($o->id, 10, null, 5);
    }

    public function test_yayin_idempotent_ve_sablon_senkronu(): void
    {
        $servis = app(DersProgramServisi::class);
        $sablon = $servis->sablonKaydet(self::SALON, null, ['hafta_gunu' => 3, 'saat' => '09:00', 'saat_bitis' => '10:00', 'kapasite' => 3, 'hizmet_id' => self::HIZMET], 5);

        $baslangic = date('Y-m-d');
        $ilk = $servis->yayinla(self::SALON, $baslangic, null, 2, 5);
        $tekrar = $servis->yayinla(self::SALON, $baslangic, null, 2, 5);
        $this->assertGreaterThan(0, $ilk['olusan']);
        $this->assertSame(0, $tekrar['olusan']);
        $this->assertSame($ilk['olusan'], $tekrar['atlanan']);

        $g = $servis->sablonKaydet(self::SALON, $sablon['sablon_id'], ['hafta_gunu' => 3, 'saat' => '11:00', 'saat_bitis' => '12:00', 'kapasite' => 5], 5);
        $this->assertSame($ilk['olusan'], $g['senkron']);
        $this->assertSame(5, (int) DersOturumu::first()->kapasite);

        $s = $servis->sablonSil(self::SALON, $sablon['sablon_id'], 5);
        $this->assertSame($ilk['olusan'], $s['temizlenen']);
    }

    public function test_otomatik_plan_dolu_slotu_atlar_oturum_uretir_ve_silince_temizler(): void
    {
        $this->studyo();
        $this->seansSat(10, 2);
        $program = app(DersProgramServisi::class);
        $sablon = $program->sablonKaydet(self::SALON, null, ['hafta_gunu' => 1, 'saat' => '09:00', 'saat_bitis' => '10:00', 'kapasite' => 1, 'hizmet_id' => self::HIZMET], 5);

        // Ilk uygun Pazartesi dolu: plan onu atlayip sonraki iki Pazartesi'ye yerlesmeli.
        $ilkPzt = date('Y-m-d', strtotime('next monday'));
        $dolu = $program->oturumUret(\App\Models\DersProgramiSablonu::find($sablon['sablon_id']), $ilkPzt);
        $this->katilim()->ekle($dolu->id, 11, null, 5);

        $tekrarli = app(DersTekrarliServisi::class);
        $k = $tekrarli->kaynaklar(self::SALON, 10)['kaynaklar'][0];
        $this->assertSame(2, $k['kalan_seans']);

        $sonuc = $tekrarli->kaydet(self::SALON, null, [
            'musteri_id' => 10, 'hizmet_id' => self::HIZMET, 'toplam_seans' => $k['kalan_seans'], 'sablonlar' => [$sablon['sablon_id']],
        ], 5);
        $this->assertSame(2, $sonuc['sonuc']['olusan']);
        $this->assertSame(1, $sonuc['sonuc']['dolu_atlanan']);
        $this->assertSame(2, $sonuc['sonuc']['oturum_olusturulan']);
        $this->assertSame(0, DB::table('adisyon_paket_seanslar')->count(), 'plan seans dusmez');

        $liste = $tekrarli->liste(self::SALON, 10)[0];
        $this->assertSame(2, $liste['yerlesen']);
        $this->assertSame([$sablon['sablon_id']], $liste['sablonlar']);

        $this->assertSame(2, $tekrarli->sil(self::SALON, $sonuc['kayit_id'], 5)['temizlenen']);
        $this->assertSame(0, DersKatilimci::where('user_id', 10)->count());
    }

    /* ---------- Mobil yolu: mobil uygulamanin bugunku davranisi, web varsayilanlari degismez ---------- */

    public function test_mobil_cikarma_tek_kisiyi_terfi_ettirir_ve_mobil_metni_gider(): void
    {
        $o = $this->oturum(1);
        $ilk = $this->katilim()->ekle($o->id, 10, null, 5);
        $b1 = $this->katilim()->ekle($o->id, 11, null, 5);
        $b2 = $this->katilim()->ekle($o->id, 12, null, 5);
        DersOturumu::whereKey($o->id)->update(['kapasite' => 2]);

        $this->katilim()->cikar($ilk['katilimci_id'], 5, true);

        $this->assertSame('rezerve', DersKatilimci::find($b1['katilimci_id'])->durum);
        $this->assertSame('bekleme', DersKatilimci::find($b2['katilimci_id'])->durum);
        $son = DB::table('v2_bildirim_kuyrugu')->orderByDesc('id')->value('payload');
        $this->assertStringContainsString('rezervasyonunuz onaylandı ✨', json_decode($son, true)['mesaj']);
    }

    public function test_mobil_ekleme_zaten_ekli_metni(): void
    {
        $o = $this->oturum();
        $this->katilim()->ekle($o->id, 10, null, 5);

        $this->expectException(CakismaException::class);
        $this->expectExceptionMessage('Bu musteri zaten ekli.');
        $this->katilim()->ekle($o->id, 10, null, 5, DersKatilimServisi::ZATEN_EKLI_MOBIL);
    }

    public function test_mobil_bekleme_satiri_isaretlenir(): void
    {
        $o = $this->oturum(1);
        $this->katilim()->ekle($o->id, 10, null, 5);
        $b = $this->katilim()->ekle($o->id, 11, null, 5);

        $sonuc = $this->katilim()->durumGuncelle($b['katilimci_id'], KatilimciDurumu::GELDI, 5, true);

        $this->assertSame('geldi', $sonuc['durum']);
        $this->assertSame('geldi', DersKatilimci::find($b['katilimci_id'])->durum);
    }

    public function test_mobil_rezerveye_donus_telafiyi_de_siler_web_korur(): void
    {
        $this->seansSat(10, 5);
        $o = $this->oturum();
        $k = $this->katilim()->ekle($o->id, 10, null, 5);

        $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::TELAFI, 5);
        $webde = $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::REZERVE, 5);
        $this->assertSame('telafi_korundu', $webde['dusum']);
        $this->assertSame(1, DB::table('adisyon_paket_seanslar')->where('geldi', 2)->count());

        DB::table('adisyon_paket_seanslar')->delete();
        $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::TELAFI, 5, true);
        $mobilde = $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::REZERVE, 5, true);
        $this->assertSame('iade', $mobilde['dusum']);
        $this->assertSame(0, DB::table('adisyon_paket_seanslar')->count());
    }

    public function test_mobil_ders_iptali_hak_iade_etmez_bekleyene_bildirmez(): void
    {
        $this->seansSat(10, 5);
        $o = $this->oturum(1);
        $k = $this->katilim()->ekle($o->id, 10, null, 5);
        $this->katilim()->ekle($o->id, 11, null, 5);
        $this->katilim()->durumGuncelle($k['katilimci_id'], KatilimciDurumu::GELDI, 5, true);
        DB::table('v2_bildirim_kuyrugu')->delete();

        $sonuc = app(DersOturumServisi::class)->iptal($o->id, 5, true);

        $this->assertSame(1, $sonuc['bildirilen']);
        $this->assertSame(1, DB::table('adisyon_paket_seanslar')->count());
        $this->assertTrue((bool) DersKatilimci::find($k['katilimci_id'])->hak_dusuldu);
    }

    public function test_mobil_duzenleme_kapasite_artisinda_terfi_ettirmez(): void
    {
        $o = $this->oturum(1);
        $this->katilim()->ekle($o->id, 10, null, 5);
        $b = $this->katilim()->ekle($o->id, 11, null, 5);

        app(DersOturumServisi::class)->kaydet(self::SALON, $o->id, ['tarih' => $o->tarih, 'saat' => '09:00', 'saat_bitis' => '10:00', 'kapasite' => 3], null, 5, false);

        $this->assertSame('bekleme', DersKatilimci::find($b['katilimci_id'])->durum);
    }

    public function test_mobil_rezervasyon_metinleri(): void
    {
        $o = $this->oturum();
        $this->katilim()->rezerveEt($o->id, self::SALON, 10);

        try {
            $this->katilim()->rezerveEt($o->id, self::SALON, 10, true);
            $this->fail('Mukerrer rezervasyon reddedilmeliydi');
        } catch (CakismaException $e) {
            $this->assertSame('Bu derse zaten kayıtlısınız.', $e->getMessage());
        }

        $this->expectExceptionMessage('Ders bulunamadı veya iptal edilmiş.');
        $this->katilim()->rezerveEt(9999, self::SALON, 10, true);
    }
}
