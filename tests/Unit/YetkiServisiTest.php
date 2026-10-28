<?php

namespace Tests\Unit;

use App\Services\Yetki\YetkiServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * YetkiServisi birebir-port dogrulamasi.
 *
 * Eski monolitteki kanonik karar zincirinin TUM dallarini izole bir sqlite
 * in-memory DB uzerinde dogrular — PAYLASILAN PROD DB'ye DOKUNMAZ. Eski
 * tests/yetki_impersonation_standalone.php'nin kapsamadigi dallari da ekler
 * (hesap sahibi / ozel kayit / eksik-anahtar tamamlama / personel default /
 * rol-5 kopuk-bag / personel-degil tam yetki).
 *
 * Not: Impersonation dali WEB session'a bagliydi; stateless API'de oturum
 * olmadigindan burada test edilmez (adapte edildi, bkz. YetkiServisi).
 */
class YetkiServisiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Izole sqlite — varsayilan baglantiyi guvenle buraya cevir.
        config([
            'database.default' => 'yetki_sqlite',
            'database.connections.yetki_sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('yetki_sqlite');
        DB::setDefaultConnection('yetki_sqlite');

        Schema::create('salon_personelleri', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('yetkili_id')->nullable();
            $t->unsignedBigInteger('salon_id');
            $t->unsignedBigInteger('role_id')->nullable();
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type')->nullable();
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('salon_id');
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
        });
        Schema::create('personel_yetki_ayarlari', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('personel_id');
            $t->unsignedBigInteger('salon_id');
            $t->string('sablon')->nullable();
            $t->text('ayarlar')->nullable();
            $t->timestamps();
        });

        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Hesap Sahibi'],
            ['id' => 5, 'name' => 'Personel'],
        ]);
    }

    private function servis(): YetkiServisi
    {
        return new YetkiServisi(); // her testte taze cache
    }

    /** Her test taze baglanti kullansin diye yardimci: yetkili+personel+rol kur. */
    private function personelKur(int $yetkiliId, int $salonId, ?int $personelId, ?int $roleId): void
    {
        if ($personelId !== null) {
            DB::table('salon_personelleri')->insert([
                'id' => $personelId, 'yetkili_id' => $yetkiliId, 'salon_id' => $salonId, 'role_id' => $roleId,
            ]);
        }
        if ($roleId !== null) {
            DB::table('model_has_roles')->insert([
                'role_id' => $roleId, 'model_type' => 'isletme', 'model_id' => $yetkiliId, 'salon_id' => $salonId,
            ]);
        }
    }

    public function test_hesap_sahibi_her_zaman_tam_yetki(): void
    {
        $this->personelKur(1, 100, 10, 1); // role_id=1 Hesap Sahibi
        $s = $this->servis();

        $this->assertTrue($s->yetkiliYetkiVar(1, 100, 'satis.tahsilat_sil'));
        $this->assertTrue($s->yetkiliYetkiVar(1, 100, 'personel.yetki_yonet'));
    }

    public function test_personel_rolu_kayit_yok_personel_defaultu(): void
    {
        $this->personelKur(2, 100, 20, 5); // role_id=5 Personel, ozel kayit YOK
        $s = $this->servis();

        // personel sablonu: tahsilat_al=true, tahsilat_sil=false, rapor.tahsilat=false
        $this->assertTrue($s->yetkiliYetkiVar(2, 100, 'satis.tahsilat_al'));
        $this->assertFalse($s->yetkiliYetkiVar(2, 100, 'satis.tahsilat_sil'));
        $this->assertFalse($s->yetkiliYetkiVar(2, 100, 'rapor.tahsilat'));
    }

    public function test_ozel_kayit_rolden_bagimsiz_uygulanir(): void
    {
        // Personel ROLU DEGIL (model_has_roles yok) ama ozel kayit VAR → kayit uygulanir.
        $this->personelKur(3, 100, 30, null);
        DB::table('personel_yetki_ayarlari')->insert([
            'personel_id' => 30, 'salon_id' => 100, 'sablon' => 'sekreter',
            'ayarlar' => json_encode(['satis.tahsilat_al' => false]),
        ]);
        $s = $this->servis();

        // Acikca false yazilan anahtar → false (rol "sahip gibi" olsa bile kisitlanir)
        $this->assertFalse($s->yetkiliYetkiVar(3, 100, 'satis.tahsilat_al'));
    }

    public function test_eksik_anahtar_kendi_sablonundan_tamamlanir(): void
    {
        // sekreter sablonu kayitli; ayarlar'da OLMAYAN anahtar sekreter default'undan gelir.
        $this->personelKur(3, 100, 30, null);
        DB::table('personel_yetki_ayarlari')->insert([
            'personel_id' => 30, 'salon_id' => 100, 'sablon' => 'sekreter',
            'ayarlar' => json_encode(['satis.tahsilat_al' => false]),
        ]);
        $s = $this->servis();

        // rapor.tahsilat ayarlar'da yok → sekreter default = true
        $this->assertTrue($s->yetkiliYetkiVar(3, 100, 'rapor.tahsilat'));
        // satis.tahsilat_sil ayarlar'da yok → sekreter default = false
        $this->assertFalse($s->yetkiliYetkiVar(3, 100, 'satis.tahsilat_sil'));
    }

    public function test_rol5_kopuk_bag_personel_defaultu(): void
    {
        // salon_personelleri satiri YOK (bag kopuk) ama model_has_roles role_id=5 VAR.
        $this->personelKur(4, 100, null, 5);
        $s = $this->servis();

        $this->assertTrue($s->yetkiliYetkiVar(4, 100, 'satis.tahsilat_al'));   // personel default true
        $this->assertFalse($s->yetkiliYetkiVar(4, 100, 'satis.tahsilat_sil')); // personel default false
        $this->assertFalse($s->yetkiliYetkiVar(4, 100, 'personel.yetki_yonet'));
    }

    public function test_personel_rolu_degil_ve_kayit_yok_tam_yetki(): void
    {
        // Ne personel rolu ne ozel kayit → sahip/direkt yetkili → tam yetki.
        $this->personelKur(5, 100, null, null);
        $s = $this->servis();

        $this->assertTrue($s->yetkiliYetkiVar(5, 100, 'satis.tahsilat_sil'));
        $this->assertTrue($s->yetkiliYetkiVar(5, 100, 'personel.yetki_yonet'));
    }

    public function test_musteri_telefon_gor_tum_sablonlarda_kapali(): void
    {
        // Invariant: musteri.telefon_gor default KAPALI (personel rolu, kayit yok).
        $this->personelKur(6, 100, 60, 5);
        $s = $this->servis();

        $this->assertFalse($s->yetkiliYetkiVar(6, 100, 'musteri.telefon_gor'));
    }
}
