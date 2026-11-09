<?php

namespace Tests\Unit\Yetki;

use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pasif personel kapsami: yalniz aktif=1 personel satiri salon verir; tamamen pasif salondaki rol satiri yalniz
 * hesap sahibine kapsam verir; personel satiri olmayan yetkili (rol satiriyla) korunur. Izole sqlite.
 */
class KapsamServisiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'kapsam_sqlite',
            'database.connections.kapsam_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('kapsam_sqlite');
        DB::setDefaultConnection('kapsam_sqlite');
        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->id();
            $t->integer('yetkili_id')->nullable();
            $t->integer('salon_id');
            $t->integer('role_id')->nullable();
            $t->integer('aktif')->nullable();
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->integer('role_id');
            $t->string('model_type')->nullable();
            $t->integer('model_id');
            $t->integer('salon_id');
        });
        Schema::create('personel_yetki_ayarlari', function (Blueprint $t) {
            $t->id();
            $t->integer('personel_id');
            $t->integer('salon_id');
            $t->string('sablon')->nullable();
            $t->text('ayarlar')->nullable();
            $t->timestamps();
        });
    }

    private function personel(int $yetkili, int $salon, int $rol, int $aktif): void
    {
        DB::table('salon_personelleri')->insert(['yetkili_id' => $yetkili, 'salon_id' => $salon, 'role_id' => $rol, 'aktif' => $aktif]);
        DB::table('model_has_roles')->insert(['role_id' => $rol, 'model_type' => 'isletme', 'model_id' => $yetkili, 'salon_id' => $salon]);
    }

    public function test_saf_kural(): void
    {
        $this->assertSame([10], KapsamServisi::salonlariBirlestir([['salon_id' => 10, 'aktif' => true]], [['salon_id' => 10, 'role_id' => 5]]));
        // Tamamen pasif salondaki personel rolu kapsam vermez; hesap sahibi rolu verir.
        $this->assertSame([], KapsamServisi::salonlariBirlestir([['salon_id' => 10, 'aktif' => false]], [['salon_id' => 10, 'role_id' => 2]]));
        $this->assertSame([10], KapsamServisi::salonlariBirlestir([['salon_id' => 10, 'aktif' => false]], [['salon_id' => 10, 'role_id' => 1]]));
        // Personel satiri olmayan salon (coklu sube yetkilisi) rol satiriyla korunur.
        $this->assertSame([20], KapsamServisi::salonlariBirlestir([], [['salon_id' => 20, 'role_id' => 3]]));
        // Hesap sahibi personel satiri (role_id=1) pasif olsa da kapsam verir; rol satiri olmasa da.
        $this->assertSame([10], KapsamServisi::salonlariBirlestir([['salon_id' => 10, 'aktif' => false, 'role_id' => 1]], []));
        $this->assertSame([], KapsamServisi::salonlariBirlestir([['salon_id' => 10, 'aktif' => false, 'role_id' => 2]], [['salon_id' => 10, 'role_id' => 2]]));
        // Ayni salonda bir aktif bir pasif satir: aktif kazanir.
        $this->assertSame([10], KapsamServisi::salonlariBirlestir([['salon_id' => 10, 'aktif' => false], ['salon_id' => 10, 'aktif' => true]], []));
    }

    public function test_pasif_personel_salonu_kapsam_disi_aktif_salon_kalir(): void
    {
        $this->personel(7, 100, 5, 0);
        $this->personel(7, 200, 5, 1);
        $k = new KapsamServisi();

        $this->assertSame([200], $k->kullaniciSalonlari(7));
        $this->assertFalse($k->salonaBagliMi(7, 100));
        $this->assertTrue($k->salonaBagliMi(7, 200));
        $this->assertFalse($k->tamamenPasifMi(7));
    }

    public function test_tamamen_pasif_personel_hicbir_salona_erisemez_ve_guard_reddeder(): void
    {
        $this->personel(8, 100, 2, 0);
        $k = new KapsamServisi();

        $this->assertSame([], $k->kullaniciSalonlari(8));
        $this->assertTrue($k->tamamenPasifMi(8));
    }

    public function test_hesap_sahibi_ve_personelsiz_yetkili_korunur(): void
    {
        $this->personel(1, 100, 1, 0);
        DB::table('model_has_roles')->insert(['role_id' => 3, 'model_type' => 'isletme', 'model_id' => 9, 'salon_id' => 300]);
        $k = new KapsamServisi();

        $this->assertSame([100], $k->kullaniciSalonlari(1));
        $this->assertFalse($k->tamamenPasifMi(1));
        $this->assertSame([300], $k->kullaniciSalonlari(9));
        $this->assertFalse($k->tamamenPasifMi(9), 'personel satiri olmayan hesap tamamen pasif sayilmaz');
    }

    public function test_pasif_hesap_sahibi_personel_satiri_rol_kaydi_olmadan_korunur(): void
    {
        DB::table('salon_personelleri')->insert(['yetkili_id' => 4, 'salon_id' => 100, 'role_id' => 1, 'aktif' => 0]);
        $k = new KapsamServisi();

        $this->assertSame([100], $k->kullaniciSalonlari(4));
        $this->assertFalse($k->tamamenPasifMi(4));
    }

    public function test_yetki_motoru_pasif_personel_satirini_yetki_kaynagi_saymaz(): void
    {
        // Pasif personelin ozel kaydi tum yetkiyi acsa da kullanilmaz: rol 5 varsayilani uygulanir.
        $this->personel(5, 100, 5, 0);
        DB::table('personel_yetki_ayarlari')->insert(['personel_id' => 1, 'salon_id' => 100, 'sablon' => 'ozel', 'ayarlar' => json_encode(['satis.tahsilat_sil' => true])]);

        $this->assertFalse((new YetkiServisi())->yetkiliYetkiVar(5, 100, 'satis.tahsilat_sil'));
        $this->assertNull((new YetkiServisi())->efektifYetkiler(5, 100)['personel_id']);
    }
}
