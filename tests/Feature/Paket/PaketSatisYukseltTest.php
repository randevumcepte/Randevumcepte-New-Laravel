<?php

namespace Tests\Feature\Paket;

use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Services\Paket\PaketSatisServisi;
use App\Services\Paket\PaketYukseltServisi;
use App\Services\Satis\KalemServisi;
use App\Services\Tahsilat\Mobil\MobilAlacakOkumaServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Paket satisi (Paket Yonetimi, Yeni Satis, Adisyon Detayi — tek yol KalemServisi) ve Paketi Yukselt.
 * Izole sqlite: yalniz paket, hizmet, adisyon ve seans tablolari.
 */
class PaketSatisYukseltTest extends TestCase
{
    private const SALON = 20;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'paket_sqlite',
            'database.connections.paket_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('paket_sqlite');
        DB::setDefaultConnection('paket_sqlite');
        $this->semaKur();
    }

    public function test_varsayilan_seans_bolge_basina_hak(): void
    {
        $paket = $this->paket('Lazer 3 Bolge', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]]);
        $adisyon = $this->adisyon();

        $k = app(KalemServisi::class)->ekle($adisyon, 'paket', $paket, []);

        $ap = DB::table('adisyon_paketler')->find($k['kalem_id']);
        $this->assertSame(8, (int) $ap->seans_sayisi); // 24 degil
        $this->assertSame(8, (int) $ap->bekleyen_seans);
        $this->assertSame(3000.0, (float) $ap->fiyat);
        $this->assertSame($paket, (int) $ap->paket_id);
    }

    public function test_hizmet_secimi_farkliysa_satisa_ozel_kopya_ve_secim_fiyati(): void
    {
        $paket = $this->paket('Lazer 3 Bolge', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]]);
        $adisyon = $this->adisyon();

        // 3. bolge cikarildi, salonda tanimli 4. hizmet eklendi (salon fiyati 700).
        $k = app(KalemServisi::class)->ekle($adisyon, 'paket', $paket, ['hizmetler' => [1, 2, 4], 'seans' => 6]);

        $ap = DB::table('adisyon_paketler')->find($k['kalem_id']);
        $this->assertNotSame($paket, (int) $ap->paket_id);
        $this->assertSame(0, (int) DB::table('paketler')->where('id', $ap->paket_id)->value('aktif'));
        $this->assertEqualsCanonicalizing([1, 2, 4], DB::table('paket_hizmetler')->where('paket_id', $ap->paket_id)->pluck('hizmet_id')->map(fn ($v) => (int) $v)->all());
        $this->assertEqualsCanonicalizing([1, 2, 3], DB::table('paket_hizmetler')->where('paket_id', $paket)->pluck('hizmet_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2700.0, (float) $ap->fiyat);
        $this->assertSame(6, (int) $ap->seans_sayisi);
        $this->assertSame(2700.0, app(KalemServisi::class)->katalogFiyati('paket', $paket, self::SALON, 1, [1, 2, 4]));
    }

    public function test_salonda_olmayan_hizmet_eklenemez(): void
    {
        $paket = $this->paket('Lazer', [[1, 8, 1000]]);
        $this->expectException(GecersizIslemException::class);
        app(KalemServisi::class)->ekle($this->adisyon(), 'paket', $paket, ['hizmetler' => [1, 99]]);
    }

    public function test_paket_yonetimi_satisi_ayni_yoldan(): void
    {
        $paket = $this->paket('Lazer 3 Bolge', [[1, 8, 1000], [2, 5, 1000], [3, 3, 1000]]);

        $r = app(PaketSatisServisi::class)->sat(self::SALON, [
            'musteri_id' => 7, 'personel_id' => 3, 'idempotency_key' => 'k1',
            'kalemler' => [['paket_id' => $paket, 'hizmetler' => [1, 2]]],
        ], ['yetkili_id' => 1, 'personel_id' => 3, 'fiyat_degistirebilir' => false]);

        $ap = DB::table('adisyon_paketler')->where('adisyon_id', $r['adisyon_id'])->first();
        $this->assertSame(8, (int) $ap->seans_sayisi);
        $this->assertSame(2000.0, (float) $ap->fiyat);
        $this->assertNotSame($paket, (int) $ap->paket_id);
    }

    public function test_yukseltme_adaylari_yalniz_aktif_ust_kume(): void
    {
        $kucuk = $this->paket('Lazer 2', [[1, 8, 1000], [2, 8, 1000]]);
        $buyuk = $this->paket('Lazer 3', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]]);
        $this->paket('Farkli', [[1, 8, 500], [4, 8, 500], [5, 8, 500]]);
        $this->paket('Pasif Kopya', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]], aktif: 0);
        $ap = $this->satilmis($kucuk, 2000, 2);
        DB::table('tahsilat_paketler')->insert(['adisyon_paket_id' => $ap, 'tutar' => 500]);

        $a = app(PaketYukseltServisi::class)->adaylar($ap, self::SALON);

        $this->assertSame([$buyuk], array_column($a['adaylar'], 'paket_id'));
        $this->assertSame(8, $a['adaylar'][0]['seans_per']);
        $this->assertSame([3], array_column($a['adaylar'][0]['ek_hizmetler'], 'hizmet_id'));
        $this->assertSame(500.0, $a['mevcut_odenen']);
        $this->assertSame('Lazer 2', $a['mevcut_paket_adi']);
    }

    public function test_yukselt_yerinde_donusturur_kayitlari_korur(): void
    {
        $kucuk = $this->paket('Lazer 2', [[1, 8, 1000], [2, 8, 1000]]);
        $buyuk = $this->paket('Lazer 3', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]]);
        $ap = $this->satilmis($kucuk, 2000, 2);
        DB::table('tahsilat_paketler')->insert(['adisyon_paket_id' => $ap, 'tutar' => 500]);

        $r = app(PaketYukseltServisi::class)->yukselt($ap, self::SALON, $buyuk, null, ['yetkili_id' => 1, 'fiyat_degistirebilir' => false], 'y1');

        $s = DB::table('adisyon_paketler')->find($ap);
        $this->assertSame($buyuk, (int) $s->paket_id);
        $this->assertSame(3000.0, (float) $s->fiyat);
        $this->assertSame(8, (int) $s->seans_sayisi);
        $this->assertSame(22, (int) $s->bekleyen_seans); // 8 × 3 − 2 acilmis seans
        $this->assertSame($kucuk, (int) $s->yukseltme_kaynak_paket_id);
        $this->assertSame(2, DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $ap)->count());
        $this->assertSame(1, DB::table('tahsilat_paketler')->where('adisyon_paket_id', $ap)->count());
        $this->assertSame(2500.0, $r['kalan']);

        // Ayni anahtar ikinci kez donusturmez.
        $tekrar = app(PaketYukseltServisi::class)->yukselt($ap, self::SALON, $buyuk, null, ['yetkili_id' => 1, 'fiyat_degistirebilir' => false], 'y1');
        $this->assertTrue($tekrar['replay']);

        $this->assertSame([$ap => ['paket_id' => $kucuk, 'paket_adi' => 'Lazer 2']], PaketYukseltServisi::kaynaklar([$ap]));
    }

    public function test_yukselt_fiyat_yetkisi_ve_ust_kume_denetimi(): void
    {
        $kucuk = $this->paket('Lazer 2', [[1, 8, 1000], [2, 8, 1000]]);
        $buyuk = $this->paket('Lazer 3', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]]);
        $alakasiz = $this->paket('Diger', [[4, 8, 1000], [5, 8, 1000], [6, 8, 1000]]);
        $ap = $this->satilmis($kucuk, 2000, 0);

        try {
            app(PaketYukseltServisi::class)->yukselt($ap, self::SALON, $buyuk, 2500.0, ['yetkili_id' => 1, 'fiyat_degistirebilir' => false], 'y2');
            $this->fail('Yetkisiz fiyat kabul edildi');
        } catch (YetkisizErisimException) {
        }

        $this->expectException(GecersizIslemException::class);
        app(PaketYukseltServisi::class)->yukselt($ap, self::SALON, $alakasiz, null, ['yetkili_id' => 1, 'fiyat_degistirebilir' => true], 'y3');
    }

    public function test_senetli_pakette_fiyat_degisen_yukseltme_reddedilir(): void
    {
        $kucuk = $this->paket('Lazer 2', [[1, 8, 1000], [2, 8, 1000]]);
        $buyuk = $this->paket('Lazer 3', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]]);
        $ap = $this->satilmis($kucuk, 2000, 0);
        DB::table('adisyon_paketler')->where('id', $ap)->update(['senet_id' => 5]);

        $this->expectException(GecersizIslemException::class);
        app(PaketYukseltServisi::class)->yukselt($ap, self::SALON, $buyuk, null, ['yetkili_id' => 1, 'fiyat_degistirebilir' => true], 'y4');
    }

    public function test_mobil_adisyon_cevabinda_yukseltme_kaynagi_web_ile_ayni_alan(): void
    {
        // Mobil tahsilat / adisyon duzenleme ekranlarinin okudugu uc (tum-alacaklar) icin ek tablolar.
        foreach (['adisyon_hizmetler', 'adisyon_urunler'] as $tablo) {
            Schema::create($tablo, function (Blueprint $t) {
                $t->id();
                $t->integer('adisyon_id');
            });
        }
        foreach (['senetler', 'taksitli_tahsilatlar'] as $tablo) {
            Schema::create($tablo, function (Blueprint $t) {
                $t->id();
                $t->integer('salon_id');
                $t->integer('user_id');
            });
        }
        $kucuk = $this->paket('Lazer 2', [[1, 8, 1000], [2, 8, 1000]]);
        $buyuk = $this->paket('Lazer 3', [[1, 8, 1000], [2, 8, 1000], [3, 8, 1000]]);
        $ap = $this->satilmis($kucuk, 2000, 0);
        $digerAp = $this->satilmis($kucuk, 2000, 0);
        DB::table('tahsilat_paketler')->insert(['adisyon_paket_id' => $ap, 'tutar' => 500]);
        app(PaketYukseltServisi::class)->yukselt($ap, self::SALON, $buyuk, null, ['yetkili_id' => 1, 'fiyat_degistirebilir' => false], 'y5');

        $servis = app(MobilAlacakOkumaServisi::class);
        $adisyonId = (int) DB::table('adisyon_paketler')->where('id', $ap)->value('adisyon_id');
        $satir = $servis->tumAlacaklar(self::SALON, 7, $adisyonId)['adisyon_paket'];

        $this->assertCount(1, $satir);
        $this->assertSame(['paket_id' => $kucuk, 'paket_adi' => 'Lazer 2'], $satir[0]['yukseltme_kaynak']);
        $this->assertSame(2500.0, $satir[0]['fiyat']); // mobil alani kalan tutardir (degismedi)
        $this->assertSame('Lazer 3', $satir[0]['paket']->paket_adi);

        $digerAdisyon = (int) DB::table('adisyon_paketler')->where('id', $digerAp)->value('adisyon_id');
        $this->assertNull($servis->tumAlacaklar(self::SALON, 7, $digerAdisyon)['adisyon_paket'][0]['yukseltme_kaynak']);
    }

    // ───────────────────────── kurulum ─────────────────────────

    /** @param  array<int,array{0:int,1:int,2:float}>  $hizmetler  [hizmet_id, seans, fiyat] */
    public function test_satistaki_paketin_seans_sayisi_degisir_bekleyen_yeniden_hesaplanir(): void
    {
        $ap = $this->satilmis($this->paket('Lazer', [[1, 8, 1000]]), 1000, 0);
        DB::table('adisyon_paketler')->where('id', $ap)->update(['kullanilan_seans' => 2, 'kullanilmayan_seans' => 1]);
        $servis = app(KalemServisi::class);

        $servis->paketSeansGuncelle($ap, '12');
        $this->assertSame([12, 9], [(int) DB::table('adisyon_paketler')->where('id', $ap)->value('seans_sayisi'), (int) DB::table('adisyon_paketler')->where('id', $ap)->value('bekleyen_seans')]);

        // Kullanilmis seansin (3) altina inilmez; gecersiz sayi ve sinirsiz paket reddedilir.
        $this->assertHata(fn () => $servis->paketSeansGuncelle($ap, '2'), CakismaException::class);
        $this->assertHata(fn () => $servis->paketSeansGuncelle($ap, '0'), GecersizIslemException::class);
        $this->assertHata(fn () => $servis->paketSeansGuncelle($ap, 'abc'), GecersizIslemException::class);
        DB::table('adisyon_paketler')->where('id', $ap)->update(['sinirsiz' => 1]);
        $this->assertHata(fn () => $servis->paketSeansGuncelle($ap, '20'), GecersizIslemException::class);
        $this->assertSame(12, (int) DB::table('adisyon_paketler')->where('id', $ap)->value('seans_sayisi'));
    }

    private function assertHata(callable $is, string $sinif): void
    {
        try {
            $is();
            $this->fail($sinif.' bekleniyordu');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($sinif, $e);
        }
    }

    private function paket(string $ad, array $hizmetler, int $aktif = 1): int
    {
        $id = DB::table('paketler')->insertGetId([
            'salon_id' => self::SALON, 'paket_adi' => $ad, 'aktif' => $aktif, 'sinirsiz' => 0,
            'fiyat' => array_sum(array_column($hizmetler, 2)), 'miktar' => array_sum(array_column($hizmetler, 1)),
        ]);
        foreach ($hizmetler as [$hizmet, $seans, $fiyat]) {
            DB::table('paket_hizmetler')->insert(['paket_id' => $id, 'hizmet_id' => $hizmet, 'seans' => $seans, 'fiyat' => $fiyat]);
        }

        return $id;
    }

    private function adisyon(): int
    {
        return DB::table('adisyonlar')->insertGetId(['salon_id' => self::SALON, 'user_id' => 7, 'tarih' => '2026-11-01']);
    }

    private function satilmis(int $paketId, float $fiyat, int $acilanSeans): int
    {
        $ap = DB::table('adisyon_paketler')->insertGetId([
            'adisyon_id' => $this->adisyon(), 'paket_id' => $paketId, 'fiyat' => $fiyat, 'seans_sayisi' => 8, 'bekleyen_seans' => 8,
            'kullanilan_seans' => 0, 'kullanilmayan_seans' => 0,
        ]);
        for ($i = 0; $i < $acilanSeans; $i++) {
            DB::table('adisyon_paket_seanslar')->insert(['adisyon_paket_id' => $ap, 'hizmet_id' => 1, 'seans_tarih' => '2026-11-0'.($i + 1)]);
        }

        return $ap;
    }

    private function semaKur(): void
    {
        $zaman = fn (Blueprint $t) => $t->timestamps();
        Schema::create('paketler', function (Blueprint $t) use ($zaman) {
            $t->id();
            $t->integer('salon_id')->nullable();
            $t->string('paket_adi')->nullable();
            $t->decimal('fiyat', 10, 2)->nullable();
            $t->integer('miktar')->nullable();
            $t->integer('aktif')->default(1);
            $t->integer('sure')->nullable();
            $t->integer('seans_araligi')->nullable();
            $t->integer('sinirsiz')->default(0);
            $t->integer('gecerlilik_sayi')->nullable();
            $t->string('gecerlilik_birim')->nullable();
            $t->integer('gunluk_limit')->nullable();
            $zaman($t);
        });
        Schema::create('paket_hizmetler', function (Blueprint $t) use ($zaman) {
            $t->id();
            $t->integer('paket_id');
            $t->integer('hizmet_id');
            $t->integer('seans')->default(0);
            $t->decimal('fiyat', 10, 2)->default(0);
            $zaman($t);
        });
        Schema::create('hizmetler', function (Blueprint $t) use ($zaman) {
            $t->id();
            $t->string('hizmet_adi');
            $zaman($t);
        });
        Schema::create('salon_sunulan_hizmetler', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('hizmet_id');
            $t->decimal('baslangic_fiyat', 10, 2)->nullable();
            $t->integer('sure_dk')->nullable();
            $t->integer('aktif')->default(1);
        });
        Schema::create('adisyonlar', function (Blueprint $t) use ($zaman) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('user_id')->nullable();
            $t->integer('olusturan_id')->nullable();
            $t->date('tarih')->nullable();
            $zaman($t);
        });
        Schema::create('adisyon_paketler', function (Blueprint $t) use ($zaman) {
            $t->id();
            foreach (['adisyon_id', 'paket_id', 'personel_id', 'seans_sayisi', 'bekleyen_seans', 'kullanilan_seans', 'kullanilmayan_seans',
                'senet_id', 'taksitli_tahsilat_id', 'sinirsiz', 'gecerlilik_sayi', 'gunluk_limit', 'yukseltme_kaynak_paket_id', 'hediye'] as $k) {
                $t->integer($k)->nullable();
            }
            $t->string('gecerlilik_birim')->nullable();
            $t->decimal('fiyat', 10, 2)->nullable();
            $t->decimal('indirim_tutari', 10, 2)->nullable();
            $zaman($t);
        });
        Schema::create('adisyon_paket_seanslar', function (Blueprint $t) use ($zaman) {
            $t->id();
            $t->integer('adisyon_paket_id')->nullable();
            $t->integer('hizmet_id')->nullable();
            $t->date('seans_tarih')->nullable();
            $t->string('seans_saat')->nullable();
            $t->integer('seans_no')->nullable();
            $t->integer('randevu_id')->nullable();
            $t->integer('personel_id')->nullable();
            $t->integer('geldi')->nullable();
            $zaman($t);
        });
        Schema::create('tahsilat_paketler', function (Blueprint $t) use ($zaman) {
            $t->id();
            $t->integer('tahsilat_id')->nullable();
            $t->integer('adisyon_paket_id');
            $t->decimal('tutar', 10, 2);
            $zaman($t);
        });
        Schema::create('api_idempotency_anahtarlari', function (Blueprint $t) use ($zaman) {
            $t->id();
            $t->string('anahtar');
            $t->string('uc');
            $t->integer('yetkili_id')->nullable();
            $t->integer('salon_id')->nullable();
            $t->integer('yanit_kodu')->nullable();
            $t->text('yanit_json')->nullable();
            $t->unique(['anahtar', 'uc']);
            $zaman($t);
        });
        Schema::create('musteri_portfoy', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->integer('user_id');
        });
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->string('personel_adi')->nullable();
        });
        DB::table('musteri_portfoy')->insert(['salon_id' => self::SALON, 'user_id' => 7]);
        DB::table('salon_personelleri')->insert(['id' => 3, 'salon_id' => self::SALON, 'personel_adi' => 'Ayse']);
        foreach ([1 => 'Bacak', 2 => 'Koltuk Alti', 3 => 'Kol', 4 => 'Yuz', 5 => 'Sirt', 6 => 'Karin'] as $id => $ad) {
            DB::table('hizmetler')->insert(['id' => $id, 'hizmet_adi' => $ad]);
            DB::table('salon_sunulan_hizmetler')->insert(['salon_id' => self::SALON, 'hizmet_id' => $id, 'baslangic_fiyat' => 700, 'sure_dk' => 30]);
        }
        DB::table('salon_sunulan_hizmetler')->insert(['salon_id' => 99, 'hizmet_id' => 99, 'baslangic_fiyat' => 1]);
    }
}
