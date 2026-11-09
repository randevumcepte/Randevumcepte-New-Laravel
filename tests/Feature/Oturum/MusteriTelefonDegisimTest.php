<?php

namespace Tests\Feature\Oturum;

use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Services\MusteriHesap\MusteriProfilServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PATCH /musteri-uygulama/ben telefon degisimi: baska hesaptaki numara genel mesajla reddedilir (numara
 * sizdirilmaz), degisim denemesi gunde sinirli; ayni numara (uygulama her kayitta gonderir) sinira islemez.
 */
class MusteriTelefonDegisimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'tel_sqlite',
            'database.connections.tel_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'online.telefon_degisim_gunluk' => 2,
        ]);
        DB::purge('tel_sqlite');
        DB::setDefaultConnection('tel_sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('cep_telefon')->nullable();
        });
        DB::table('users')->insert([['id' => 1, 'cep_telefon' => '5321112233'], ['id' => 2, 'cep_telefon' => '5324445566']]);
    }

    private function yeniTelefon(string $tel): string
    {
        $m = new \ReflectionMethod(MusteriProfilServisi::class, 'yeniTelefon');

        return $m->invoke(app(MusteriProfilServisi::class), 1, $tel);
    }

    public function test_baska_hesaptaki_numara_genel_mesajla_reddedilir_ve_deneme_sinirli(): void
    {
        foreach (range(1, 2) as $i) {
            try {
                $this->yeniTelefon('0532 444 55 66');
                $this->fail('dolu numara reddedilmeliydi');
            } catch (GecersizIslemException $e) {
                $this->assertSame(MusteriProfilServisi::TELEFON_KULLANILAMAZ, $e->getMessage());
            }
        }

        $this->expectException(CokFazlaIstekException::class);
        $this->yeniTelefon('5327778899');
    }

    public function test_ayni_numara_sinira_islemez(): void
    {
        foreach (range(1, 5) as $i) {
            $this->assertSame('5321112233', $this->yeniTelefon('0532 111 22 33'));
        }
        $this->assertSame('5327778899', $this->yeniTelefon('5327778899'));
    }
}
