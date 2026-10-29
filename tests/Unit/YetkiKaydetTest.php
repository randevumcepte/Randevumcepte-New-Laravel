<?php

namespace Tests\Unit;

use App\Services\Yetki\YetkiServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * YetkiServisi::ayarlariKaydet (yeni yazma metodu) dogrulamasi — izole sqlite,
 * PAYLASILAN PROD DB'ye DOKUNMAZ. Whitelist, bilinmeyen sablon→ozel, updateOrCreate
 * ve kayit sonrasi okuma paritesini (cache invalidasyonu) test eder.
 */
class YetkiKaydetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
        // Personel rolunde bir kayit (role_id=5) — kaydet sonrasi kisit gorunur olsun.
        DB::table('salon_personelleri')->insert([
            'id' => 30, 'yetkili_id' => 3, 'salon_id' => 100, 'role_id' => 5,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => 5, 'model_type' => 'isletme', 'model_id' => 3, 'salon_id' => 100,
        ]);
    }

    public function test_kaydet_whitelist_ve_bilinmeyen_sablon_ozel(): void
    {
        $s = new YetkiServisi();
        $kayit = $s->ayarlariKaydet(30, 100, 'uydurma_sablon', [
            'satis.tahsilat_al' => true,
            'kotu.anahtar'      => true, // tanimsiz → atilmali
        ]);

        $this->assertSame('ozel', $kayit->sablon);
        $this->assertArrayHasKey('satis.tahsilat_al', $kayit->ayarlar);
        $this->assertArrayNotHasKey('kotu.anahtar', $kayit->ayarlar);
    }

    public function test_kaydet_sonrasi_okuma_paritesi(): void
    {
        $s = new YetkiServisi();
        // Once oku (cache dolsun), sonra kaydet, sonra tekrar oku → yeni deger gelmeli.
        $this->assertTrue($s->yetkiliYetkiVar(3, 100, 'satis.tahsilat_al')); // personel default true

        $s->ayarlariKaydet(30, 100, 'personel', ['satis.tahsilat_al' => false]);

        $this->assertFalse($s->yetkiliYetkiVar(3, 100, 'satis.tahsilat_al')); // cache invalidasyonu sonrasi false
    }

    public function test_kaydet_updateOrCreate_tek_satir(): void
    {
        $s = new YetkiServisi();
        $s->ayarlariKaydet(30, 100, 'personel', ['satis.tahsilat_al' => true]);
        $s->ayarlariKaydet(30, 100, 'sekreter', ['satis.tahsilat_al' => false]);

        $this->assertSame(1, DB::table('personel_yetki_ayarlari')
            ->where('personel_id', 30)->where('salon_id', 100)->count());
        $this->assertSame('sekreter', DB::table('personel_yetki_ayarlari')
            ->where('personel_id', 30)->where('salon_id', 100)->value('sablon'));
    }
}
