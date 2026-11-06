<?php

namespace Tests\Feature\Sistem;

use App\Services\Santral\SantralIstemcisi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Dakika listesi: havuz hesabi DakikaServisi'nden, salon basina tek santral istegi, destek kapsami. */
class DakikaYonetimiTest extends TestCase
{
    use EntegrasyonKurulum;

    private const KOK = '/api/v2/sistem/dakika';

    private $istemci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ortamKur();
        Schema::create('sabit_numaralar', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id');
            $t->string('numara')->nullable();
        });
        Schema::create('salon_dakika_paketi', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id')->unique();
            $t->integer('tanimli_dakika')->default(0);
            $t->date('sayim_baslangic')->nullable();
            $t->string('guncelleyen')->nullable();
            $t->timestamps();
        });
        $this->istemci = Mockery::mock(SantralIstemcisi::class);
        $this->app->instance(SantralIstemcisi::class, $this->istemci);
    }

    private function salon(int $id, array $alan = []): void
    {
        DB::table('salonlar')->insert($alan + ['id' => $id, 'salon_adi' => "Salon {$id}", 'uyelik_turu' => 3, 'uyelik_bitis_tarihi' => '2099-01-01', 'created_at' => '2026-01-15 10:00:00', 'musteri_yetkili_id' => 50]);
        DB::table('sabit_numaralar')->insert(['salon_id' => $id, 'numara' => (string) (8500000000 + $id)]);
    }

    public function test_havuz_toplam_ve_kullanim_siralamasi(): void
    {
        $this->girisYap('izleyici');
        $this->salon(1);
        $this->salon(2);
        DB::table('salon_dakika_paketi')->insert([
            ['salon_id' => 1, 'tanimli_dakika' => 1000, 'sayim_baslangic' => '2026-02-01'],
            ['salon_id' => 2, 'tanimli_dakika' => 100, 'sayim_baslangic' => null],
        ]);
        $this->istemci->shouldReceive('dakikaOzeti')->once()->with('8500000001', '2026-02-01', date('Y-m-d'), null)->andReturn(['dakika' => 100.04, 'adet' => 5, 'hata' => false]);
        $this->istemci->shouldReceive('dakikaOzeti')->once()->with('8500000002', '2026-01-15', date('Y-m-d'), null)->andReturn(['dakika' => 95.0, 'adet' => 2, 'hata' => false]);

        $this->getJson(self::KOK)->assertOk()
            ->assertJsonPath('veri.izinler.yaz', false)
            ->assertJsonPath('veri.data.0.salon_id', 2)->assertJsonPath('veri.data.0.yuzde', 95)
            ->assertJsonPath('veri.data.1.salon_id', 1)->assertJsonPath('veri.data.1.kullanilan', 100.0)
            ->assertJsonPath('veri.ozet.tanimli', 1100)->assertJsonPath('veri.total', 2);
    }

    public function test_esantral_disi_suresi_dolmus_ve_trunksuz_salon_listelenmez(): void
    {
        $this->girisYap('yonetici');
        $this->salon(1, ['uyelik_turu' => 1]);
        $this->salon(2, ['uyelik_bitis_tarihi' => '2020-01-01']);
        DB::table('salonlar')->insert(['id' => 3, 'salon_adi' => 'Trunksuz', 'uyelik_turu' => 3, 'uyelik_bitis_tarihi' => '2099-01-01']);
        $this->istemci->shouldNotReceive('dakikaOzeti');

        $this->getJson(self::KOK)->assertOk()->assertJsonPath('veri.total', 0);
    }

    public function test_destek_yalniz_kendi_salonlari(): void
    {
        $this->salon(1);
        $this->salon(2, ['musteri_yetkili_id' => 60]);
        $this->girisYap('destek', 50);
        $this->istemci->shouldReceive('dakikaOzeti')->once()->andReturn(['dakika' => 0.0, 'adet' => 0, 'hata' => false]);

        $this->getJson(self::KOK)->assertOk()->assertJsonPath('veri.total', 1)->assertJsonPath('veri.data.0.salon_id', 1);
    }

    public function test_santral_art_arda_hata_verince_istek_kesilir(): void
    {
        $this->girisYap('yonetici');
        foreach (range(1, 5) as $id) {
            $this->salon($id);
        }
        $this->istemci->shouldReceive('dakikaOzeti')->times(3)->andReturn(['dakika' => 0.0, 'adet' => 0, 'hata' => true]);

        $this->getJson(self::KOK)->assertOk()->assertJsonPath('veri.total', 5)->assertJsonPath('veri.data.4.hata', true);
    }

    public function test_kaydet_rol_upsert_ve_denetim(): void
    {
        $this->salon(1);
        $this->girisYap('destek', 50);
        $this->putJson(self::KOK.'/1', ['tanimli_dakika' => 500])->assertStatus(403);

        $y = $this->girisYap('yonetici');
        $this->putJson(self::KOK.'/1', ['tanimli_dakika' => -1])->assertStatus(422);
        $this->putJson(self::KOK.'/1', ['tanimli_dakika' => 500, 'sayim_baslangic' => '2026-05-01'])->assertOk();
        $this->putJson(self::KOK.'/1', ['tanimli_dakika' => 700])->assertOk();

        $p = DB::table('salon_dakika_paketi')->where('salon_id', 1)->first();
        $this->assertSame(700, (int) $p->tanimli_dakika);
        $this->assertNull($p->sayim_baslangic);
        $this->assertSame($y->name, $p->guncelleyen);
        $this->assertSame(1, DB::table('salon_dakika_paketi')->count());
        $this->assertTrue($this->denetimVar('salon_dakika_kaydet'));
    }
}
