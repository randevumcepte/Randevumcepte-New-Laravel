<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Kök adres: salona ait olmayan alan adı (panel) girişe yönlenir; boş Laravel sayfası yoktur. */
class ExampleTest extends TestCase
{
    public function test_salona_ait_olmayan_kok_adres_girise_yonlenir(): void
    {
        config([
            'database.default' => 'kok_sqlite',
            'database.connections.kok_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('kok_sqlite');
        Schema::create('salonlar', function (Blueprint $t) {
            $t->id();
            $t->string('domain')->nullable();
        });

        $this->get('http://panel.randevumcepte.com.tr/')->assertRedirect('/v2/giris');
        $this->get('http://panel.randevumcepte.com.tr/randevularim')->assertNotFound();
    }
}
