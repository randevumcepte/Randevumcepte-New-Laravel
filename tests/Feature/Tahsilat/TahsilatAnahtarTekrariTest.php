<?php

namespace Tests\Feature\Tahsilat;

use App\Services\Tahsilat\TahsilatYazServisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Tahsilat duzenleme kullanilmis anahtarla once silip sonra replay donmemeli: anahtar silmeden once denetlenir. */
class TahsilatAnahtarTekrariTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('api_idempotency_anahtarlari', function (Blueprint $t) {
            $t->id();
            $t->string('anahtar');
            $t->string('uc');
            $t->integer('yetkili_id')->nullable();
            $t->integer('salon_id')->nullable();
            $t->integer('yanit_kodu')->nullable();
            $t->text('yanit_json')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('api_idempotency_anahtarlari');
        parent::tearDown();
    }

    public function test_kullanilmis_anahtar_taninir_yanit_yazilmamis_olsa_da(): void
    {
        $servis = app(TahsilatYazServisi::class);
        $this->assertFalse($servis->anahtarKullanildi('K1'));

        DB::table('api_idempotency_anahtarlari')->insert(['anahtar' => 'K1', 'uc' => 'tahsilat']);
        DB::table('api_idempotency_anahtarlari')->insert(['anahtar' => 'K2', 'uc' => 'baska_uc']);

        $this->assertTrue($servis->anahtarKullanildi(' K1 '));
        $this->assertFalse($servis->anahtarKullanildi('K2'));
    }
}
