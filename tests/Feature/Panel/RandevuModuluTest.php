<?php

namespace Tests\Feature\Panel;

use App\Services\Panel\RandevuModulu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Randevu modülü (randevu.randevumcepte.com.tr) — host'a bağlı kısıtlı panel kuralları ve kapıları.
 * İzole sqlite; paylaşılan DB'ye dokunmaz.
 */
class RandevuModuluTest extends TestCase
{
    private const MODUL = 'https://randevu.randevumcepte.com.tr';

    private const TAM = 'https://app.randevumcepte.com.tr';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.randevu_modulu.hostlar' => ['randevu.randevumcepte.com.tr'],
            'database.default' => 'rm_sqlite',
            'database.connections.rm_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('rm_sqlite');
        DB::setDefaultConnection('rm_sqlite');
        Schema::create('salonlar', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('gecmis_randevulari_gizle')->default(0);
        });
        DB::table('salonlar')->insert([['id' => 20, 'gecmis_randevulari_gizle' => 1], ['id' => 21, 'gecmis_randevulari_gizle' => 0]]);
    }

    private function modul(string $host): RandevuModulu
    {
        $this->app->instance('request', Request::create($host.'/v2/takvim'));
        $this->app->forgetScopedInstances();

        return $this->app->make(RandevuModulu::class);
    }

    public function test_yalniz_modul_hostunda_aktif(): void
    {
        $this->assertTrue($this->modul(self::MODUL)->aktif());
        $this->assertFalse($this->modul(self::TAM)->aktif());

        config(['panel.randevu_modulu.hostlar' => []]);
        $this->assertFalse($this->modul(self::MODUL)->aktif());
    }

    public function test_menu_ogesi_yalniz_isaretliyse_gorunur(): void
    {
        $m = $this->modul(self::MODUL);
        $this->assertTrue($m->ogeGorunur(['slug' => 'takvim-v2', 'randevu_modulu' => true]));
        $this->assertFalse($m->ogeGorunur(['slug' => 'satis-takibi']));
        $this->assertTrue($this->modul(self::TAM)->ogeGorunur(['slug' => 'satis-takibi']));
    }

    public function test_sayfa_izinleri(): void
    {
        $m = $this->modul(self::MODUL);
        foreach (['v2/takvim', 'v2/takvim/20', '/v2/randevular?sube=20', 'v2/musteriler', 'v2/musteri/5', 'v2/ongorusmeler',
            'v2/formlar', 'v2/memnuniyet-anketi', 'v2/bildirim-reklamlari', 'v2/log-hareketleri', 'v2/profil', 'v2/hesabim', 'v2/giris'] as $yol) {
            $this->assertTrue($m->sayfaIzinli($yol), $yol);
        }
        foreach (['v2/ozet', '/v2/ozet?sube=20', 'v2/satislar', 'v2/yeni-satis', 'v2/tahsilat/9', 'v2/raporlar', 'v2/isletme-raporlari',
            'v2/kasa', 'v2/cagri-merkezi', 'v2/santral', 'v2/ayarlar', 'v2/personel', 'v2/paketler', 'v2/stok', 'v2/takvimx'] as $yol) {
            $this->assertFalse($m->sayfaIzinli($yol), $yol);
        }
        $this->assertTrue($this->modul(self::TAM)->sayfaIzinli('v2/ozet'));
    }

    public function test_api_kapali_uclar(): void
    {
        $m = $this->modul(self::MODUL);
        foreach (['satislar', 'adisyon/5/odeme', 'kalem/hizmet/3/fiyat', 'tahsilat', 'randevu/5/tahsilat', 'ongorusme/4/satis',
            'ozet', 'rapor/satis', 'kasa/gelirler', 'masraf', 'cagri-merkezi/acilis', 'santral/ozet', 'softphone'] as $yol) {
            $this->assertTrue($m->apiKapali($yol), $yol);
        }
        foreach (['randevu/takvim', 'randevu/5', 'randevu/liste', 'musteri/5', 'anket/ozet', 'kabuk', 'ongorusme'] as $yol) {
            $this->assertFalse($m->apiKapali($yol), $yol);
        }
        $this->assertFalse($this->modul(self::TAM)->apiKapali('satislar'));
    }

    public function test_gecmis_esigi_yalniz_modulde_ve_ayar_acikken(): void
    {
        $esik = $this->modul(self::MODUL)->gecmisEsigi(20);
        $this->assertNotNull($esik);
        $this->assertEqualsWithDelta(strtotime('-1 hour'), strtotime($esik), 5);

        $this->assertNull($this->modul(self::MODUL)->gecmisEsigi(21));
        $this->assertNull($this->modul(self::TAM)->gecmisEsigi(20));
    }

    public function test_izinsiz_sayfa_takvime_doner(): void
    {
        $this->get(self::MODUL.'/v2/ozet?sube=20')->assertRedirect('/v2/takvim?sube=20');
        $this->get(self::MODUL.'/v2/satislar/20')->assertRedirect('/v2/takvim?sube=20');
        $this->get(self::TAM.'/v2/ozet?sube=20')->assertStatus(200);
    }

    public function test_kapali_api_403(): void
    {
        $this->getJson(self::MODUL.'/api/v2/satislar?salon_id=20')
            ->assertStatus(403)->assertJsonPath('veri.kod', 'randevu_modulu');
        // Açık uç kapıdan geçer (kimliksiz → 401), tam panelde kapalı uç da yalnız kimliğe takılır.
        $this->getJson(self::MODUL.'/api/v2/randevu/takvim?salon_id=20')->assertStatus(401);
        $this->getJson(self::TAM.'/api/v2/satislar?salon_id=20')->assertStatus(401);
    }
}
