<?php

namespace Tests\Unit\Yetki;

use App\Exceptions\YetkisizErisimException;
use App\Models\PersonelYetkiAyari;
use App\Services\Personel\YetkiYonetimServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Karar motoru: rol varsayilanlari, ozel kaydin onceligi, kisit anahtari ve ekran/uc paritesi.
 * Izole sqlite — PAYLASILAN PROD DB'ye DOKUNMAZ.
 */
class YetkiRolVarsayilanTest extends TestCase
{
    private const SALON = 100;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'yetki_sqlite',
            'database.connections.yetki_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('yetki_sqlite');
        DB::setDefaultConnection('yetki_sqlite');

        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('yetkili_id')->nullable();
            $t->unsignedBigInteger('salon_id');
            $t->unsignedBigInteger('role_id')->nullable();
            $t->string('personel_adi')->nullable();
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type')->nullable();
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('salon_id');
        });
        Schema::create('personel_yetki_ayarlari', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('personel_id');
            $t->unsignedBigInteger('salon_id');
            $t->string('sablon')->nullable();
            $t->text('ayarlar')->nullable();
            $t->timestamps();
        });
        Schema::create('v2_bildirim_kuyrugu', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('salon_id');
            $t->string('tur');
            $t->unsignedBigInteger('randevu_id')->nullable();
            $t->unsignedBigInteger('yetkili_id')->nullable();
            $t->text('payload')->nullable();
            $t->integer('durum')->default(0);
            $t->integer('deneme')->default(0);
            $t->timestamps();
        });
    }

    private function motor(): YetkiServisi
    {
        return new YetkiServisi(); // her cagrida taze cache
    }

    /** $personelId = null → kopuk bag (bu salonda personel kaydi yok). */
    private function kur(int $yetkiliId, ?int $personelId, ?int $rol, ?array $ayarlar = null, string $sablon = 'ozel'): void
    {
        if ($personelId) {
            DB::table('salon_personelleri')->insert(['id' => $personelId, 'yetkili_id' => $yetkiliId, 'salon_id' => self::SALON, 'role_id' => $rol]);
        }
        if ($rol) {
            DB::table('model_has_roles')->insert(['role_id' => $rol, 'model_type' => 'isletme', 'model_id' => $yetkiliId, 'salon_id' => self::SALON]);
        }
        if ($ayarlar !== null) {
            PersonelYetkiAyari::create(['personel_id' => $personelId, 'salon_id' => self::SALON, 'sablon' => $sablon, 'ayarlar' => $ayarlar]);
        }
    }

    private function var(int $yetkiliId, string $anahtar): bool
    {
        return $this->motor()->yetkiliYetkiVar($yetkiliId, self::SALON, $anahtar);
    }

    // ---- Karar 2: ozel kaydi olmayan roller ----

    public function test_kayitsiz_sekreterde_yalniz_uc_rapor_grubu_kapali(): void
    {
        $this->kur(4, 40, 4);

        foreach (YetkiSabitleri::sekreterKapaliAnahtarlar() as $anahtar) {
            $this->assertFalse($this->var(4, $anahtar), $anahtar);
        }
        foreach (['musteri.telefon_gor', 'randevu.olustur', 'satis.tahsilat_sil', 'finans.masraf_gor', 'personel.yetki_yonet', 'ayar.salon_bilgi', 'rapor.ciro_kar_gor'] as $anahtar) {
            $this->assertTrue($this->var(4, $anahtar), $anahtar);
        }
    }

    public function test_personel_kaydi_olmayan_sekreter_de_ayni_varsayilani_alir(): void
    {
        $this->kur(4, null, 4);

        $this->assertFalse($this->var(4, 'rapor.kasa'));
        $this->assertTrue($this->var(4, 'randevu.olustur'));
    }

    /** @return array<string, array{0:int}> */
    public static function ustRoller(): array
    {
        return ['rol 1' => [1], 'rol 2' => [2], 'rol 3' => [3]];
    }

    #[DataProvider('ustRoller')]
    public function test_kayitsiz_ust_rollerde_tum_sayfalar_acik(int $rol): void
    {
        $this->kur(9, 90, $rol);

        foreach (YetkiSabitleri::tumAnahtarlar() as $anahtar) {
            $beklenen = ! in_array($anahtar, YetkiSabitleri::kisitAnahtarlari(), true);
            $this->assertSame($beklenen, $this->var(9, $anahtar), $anahtar);
        }
    }

    public function test_kayitsiz_personel_sablon_varsayilanini_alir_kopuk_bag_dahil(): void
    {
        $this->kur(5, 50, 5);
        $this->kur(6, null, 5);

        foreach ([5, 6] as $yetkili) {
            $this->assertFalse($this->var($yetkili, 'musteri.telefon_gor'));
            $this->assertFalse($this->var($yetkili, 'rapor.kasa'));
            $this->assertTrue($this->var($yetkili, 'randevu.olustur'));
        }
    }

    public function test_ozel_kayit_rol_varsayilaninin_onune_gecer(): void
    {
        $this->kur(4, 40, 4, ['rapor.kasa' => true, 'randevu.olustur' => false], 'ozel');

        $this->assertTrue($this->var(4, 'rapor.kasa'));
        $this->assertFalse($this->var(4, 'randevu.olustur'));
    }

    // ---- Karar 1: yonetici telefon ----

    public function test_kayitsiz_yonetici_telefonu_gorur(): void
    {
        foreach ([2, 3, 4] as $rol) {
            $this->kur(20 + $rol, 200 + $rol, $rol);
            $this->assertTrue($this->var(20 + $rol, 'musteri.telefon_gor'), "rol $rol");
        }
    }

    public function test_tabloda_kapaliysa_yonetici_telefonu_goremez(): void
    {
        $this->kur(3, 30, 3, ['musteri.telefon_gor' => false], 'yonetici');

        $this->assertFalse($this->var(3, 'musteri.telefon_gor'));
    }

    public function test_yonetici_sablonunda_anahtar_eksikse_varsayilan_acik(): void
    {
        $this->kur(3, 30, 3, ['randevu.olustur' => true], 'yonetici');

        $this->assertTrue($this->var(3, 'musteri.telefon_gor'));
    }

    public function test_hesap_sahibi_kapali_kayitla_kilitlenemez(): void
    {
        $this->kur(1, 10, 1, ['musteri.telefon_gor' => false, 'rapor.kasa' => false], 'ozel');

        $this->assertTrue($this->var(1, 'musteri.telefon_gor'));
        $this->assertTrue($this->var(1, 'rapor.kasa'));
    }

    // ---- Kapali adisyon kisit anahtari ----

    public function test_tam_yetkili_hesaplar_kapali_adisyonlari_gormeye_devam_eder(): void
    {
        $this->kur(1, 10, 1);                                 // hesap sahibi
        $this->kur(3, 30, 3);                                 // kayitsiz ust rol
        $this->kur(4, 40, 4);                                 // kayitsiz sekreter
        $this->kur(7, 70, 1, ['personel.kapali_adisyon_gizle' => true], 'ozel');   // sahip kilitlenemez

        foreach ([1, 3, 4, 7] as $yetkili) {
            $this->assertFalse($this->var($yetkili, 'personel.kapali_adisyon_gizle'), "yetkili $yetkili");
        }
    }

    public function test_ozel_kayitta_acilan_gizleme_uygulanir(): void
    {
        $this->kur(5, 50, 5, ['personel.kapali_adisyon_gizle' => true], 'personel');

        $this->assertTrue($this->var(5, 'personel.kapali_adisyon_gizle'));
    }

    // ---- Ekran (benimYetkilerim) = uc denetimi ----

    /** @return array<string, array{0:?int, 1:?int, 2:?array}> [personel id, rol, ozel ayarlar] */
    public static function senaryolar(): array
    {
        return [
            'sahip + personel kaydi'     => [10, 1, null],
            'sahip + personel yok'       => [null, 1, null],
            'sahip + kapali ozel kayit'  => [10, 1, ['rapor.kasa' => false]],
            'rol 2 kayitsiz'             => [10, 2, null],
            'rol 3 kayitsiz'             => [10, 3, null],
            'rol 3 ozel kayit'           => [10, 3, ['musteri.telefon_gor' => false]],
            'sekreter kayitsiz'          => [10, 4, null],
            'sekreter personel yok'      => [null, 4, null],
            'sekreter ozel kayit'        => [10, 4, ['rapor.kasa' => true]],
            'personel kayitsiz'          => [10, 5, null],
            'personel, personel kaydi yok' => [null, 5, null],
            'personel ozel kayit'        => [10, 5, ['musteri.telefon_gor' => true]],
            'rolsuz + personel kaydi'    => [10, null, null],
            'rolsuz + personel yok'      => [null, null, null],
        ];
    }

    #[DataProvider('senaryolar')]
    public function test_benim_yetkilerim_uc_denetimiyle_ayni_sonucu_verir(?int $personelId, ?int $rol, ?array $ayarlar): void
    {
        $this->kur(1, $personelId, $rol, $ayarlar);
        $benim = (new YetkiYonetimServisi($this->motor()))->benimYetkilerim(1, self::SALON);

        foreach (YetkiSabitleri::tumAnahtarlar() as $anahtar) {
            if (in_array($anahtar, YetkiSabitleri::mobilGizliAnahtarlar(), true)) {
                $this->assertArrayNotHasKey($anahtar, $benim['ayarlar']);

                continue;
            }
            $this->assertSame($this->var(1, $anahtar), $benim['ayarlar'][$anahtar], $anahtar);
        }
    }

    public function test_benim_yetkilerim_bayraklari(): void
    {
        $this->kur(1, 10, 1);
        $this->kur(4, 40, 4);
        $this->kur(5, 50, 5);
        $y = new YetkiYonetimServisi($this->motor());

        $sahip = $y->benimYetkilerim(1, self::SALON);
        $this->assertSame(['tam_yetki', false, true], [$sahip['sablon'], $sahip['personel_rolunde'], $sahip['salon_sahibi']]);

        $sekreter = $y->benimYetkilerim(4, self::SALON);
        $this->assertSame(['sekreter_varsayilan', true, false, 40], [$sekreter['sablon'], $sekreter['personel_rolunde'], $sekreter['salon_sahibi'], $sekreter['personel_id']]);

        $personel = $y->benimYetkilerim(5, self::SALON);
        $this->assertSame(['personel', true, 50], [$personel['sablon'], $personel['personel_rolunde'], $personel['personel_id']]);
    }

    public function test_yetki_ekrani_kayitsiz_hesapta_rol_varsayilanini_gosterir(): void
    {
        $this->kur(4, 40, 4);
        $this->kur(3, 30, 3);
        $y = new YetkiYonetimServisi($this->motor());

        $sekreter = $y->getir(40, self::SALON);
        $this->assertFalse($sekreter['kayit_var']);
        $this->assertFalse($sekreter['ayarlar']['rapor.kasa']);
        $this->assertTrue($sekreter['ayarlar']['randevu.olustur']);
        $this->assertSame('tam_yetki', $y->getir(30, self::SALON)['sablon']);
    }

    // ---- Kaydetme ----

    public function test_kullanici_kendi_yetkisini_degistiremez(): void
    {
        $this->kur(4, 40, 4);

        try {
            (new YetkiYonetimServisi($this->motor()))->kaydet(40, self::SALON, 'ozel', ['rapor.kasa' => true], 4);
            $this->fail('Kendi yetkisini degistirebildi');
        } catch (YetkisizErisimException $e) {
            $this->assertSame('Kendi yetkilerinizi değiştiremezsiniz', $e->getMessage());
        }
        $this->assertSame(0, DB::table('personel_yetki_ayarlari')->count());
        $this->assertSame(0, DB::table('v2_bildirim_kuyrugu')->count());
    }

    public function test_baskasinin_yetkisi_kaydedilir_ve_zorla_cikis_kuyruga_duser(): void
    {
        $this->kur(1, 10, 1);
        $this->kur(4, 40, 4);
        $motor = $this->motor();

        (new YetkiYonetimServisi($motor))->kaydet(40, self::SALON, 'ozel', ['rapor.kasa' => true, 'uydurma.anahtar' => true], 1);

        $this->assertTrue($this->var(4, 'rapor.kasa'));
        $this->assertArrayNotHasKey('uydurma.anahtar', PersonelYetkiAyari::where('personel_id', 40)->first()->ayarlar);
        $kuyruk = DB::table('v2_bildirim_kuyrugu')->first();
        $this->assertSame('yetki_degisti', $kuyruk->tur);
        $this->assertSame(40, json_decode($kuyruk->payload, true)['personel_id']);
    }
}
