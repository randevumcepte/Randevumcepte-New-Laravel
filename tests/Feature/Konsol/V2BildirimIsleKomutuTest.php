<?php

namespace Tests\Feature\Konsol;

use App\Jobs\BildirimKuyrugu\KuyrukIsi;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\IsDagitici;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Komut dongusu: sunucuya ve saglayiciya gitmez, isleyiciler sahte. */
class V2BildirimIsleKomutuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('v2_bildirim_kuyrugu', function (Blueprint $t) {
            $t->id();
            $t->integer('salon_id')->nullable();
            $t->string('tur');
            $t->integer('randevu_id')->nullable();
            $t->integer('yetkili_id')->nullable();
            $t->text('payload')->nullable();
            $t->integer('durum')->default(0);
            $t->integer('deneme')->default(0);
            $t->text('hata')->nullable();
            $t->timestamp('islenme_at')->nullable();
            $t->timestamps();
        });
        Schema::create('randevular', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });

        SahteIsleyici::$gelen = [];
        touch(sys_get_temp_dir().'/v2-bildirim-test-anahtari.json');
        config([
            'bildirim_kuyrugu.isleyici_aktif' => true,
            'bildirim_kuyrugu.firebase_anahtar_dizini' => sys_get_temp_dir(),
            'firebase_projects.default'       => 'v2-bildirim-test-anahtari.json',
            'bildirim_kuyrugu.sistem_sms.apikey' => 'test',
            'bildirim_kuyrugu.google_takvim.client_id' => 'test',
            'bildirim_kuyrugu.google_takvim.client_secret' => 'test',
            'bildirim_kuyrugu.kuyruk_adi'     => 'v2_bildirim',
            'bildirim_kuyrugu.harita'         => ['basarili' => SahteIsleyici::class, 'hatali' => HataliSahteIsleyici::class],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('v2_bildirim_kuyrugu');
        Schema::dropIfExists('randevular');
        parent::tearDown();
    }

    private function kayit(array $alan = []): int
    {
        return (int) DB::table('v2_bildirim_kuyrugu')->insertGetId($alan + [
            'salon_id' => 20, 'tur' => 'basarili', 'durum' => 6, 'deneme' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function satir(int $id): object
    {
        return DB::table('v2_bildirim_kuyrugu')->where('id', $id)->first();
    }

    private function calistir(): int
    {
        return Artisan::call('v2:bildirim-isle', ['--tek' => true]);
    }

    public function test_bayrak_kapaliyken_uyarir_ve_hic_dokunmaz(): void
    {
        config(['bildirim_kuyrugu.isleyici_aktif' => false]);
        $id = $this->kayit();

        $this->assertSame(0, $this->calistir());

        $this->assertStringContainsString('kapali', Artisan::output());
        $this->assertSame(6, (int) $this->satir($id)->durum);
        $this->assertSame([], SahteIsleyici::$gelen);
    }

    public function test_varsayilan_bayrak_kapali(): void
    {
        $varsayilan = require base_path('config/bildirim_kuyrugu.php');

        $this->assertFalse((bool) $varsayilan['isleyici_aktif']);
    }

    public function test_zamanlayici_bayrak_kapaliyken_calismaz(): void
    {
        $olay = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'v2:bildirim-isle'));
        $this->assertNotNull($olay);
        $this->assertTrue($olay->withoutOverlapping);
        $this->assertSame('* * * * *', $olay->expression);
        $this->assertTrue($olay->filtersPass($this->app));

        config(['bildirim_kuyrugu.isleyici_aktif' => false]);
        $this->assertFalse($olay->filtersPass($this->app));
    }

    public function test_kurulum_eksikse_komut_hicbir_kayda_dokunmaz(): void
    {
        config(['bildirim_kuyrugu.sistem_sms.apikey' => '']);
        $id = $this->kayit();

        $this->artisan('v2:bildirim-isle', ['--tek' => true])->assertFailed();

        $this->assertSame(6, (int) DB::table('v2_bildirim_kuyrugu')->where('id', $id)->value('durum'));
    }

    public function test_eski_isleyicinin_kuyrugu_kabul_edilmez(): void
    {
        config(['bildirim_kuyrugu.kuyruk_adi' => 'hatirlatmalar']);

        $this->artisan('v2:bildirim-isle', ['--kontrol' => true])->assertFailed();
    }

    public function test_basarili_kayit_durum_bir_olur_ve_payload_cozulur(): void
    {
        $id = $this->kayit(['payload' => json_encode(['red' => true])]);

        $this->calistir();

        $s = $this->satir($id);
        $this->assertSame(1, (int) $s->durum);
        $this->assertNotNull($s->islenme_at);
        $this->assertSame(['red' => true], SahteIsleyici::$gelen[0]['payload']);
        $this->assertStringContainsString('1 kayit islendi', Artisan::output());
    }

    public function test_bozuk_ve_bos_payload_bos_dizi_olur(): void
    {
        $this->kayit(['payload' => null]);
        $this->kayit(['payload' => '{bozuk']);

        $this->calistir();

        $this->assertSame([[], []], array_column(SahteIsleyici::$gelen, 'payload'));
    }

    public function test_hata_iki_kez_yeniden_dener_ucuncude_kalici_olur(): void
    {
        $id = $this->kayit(['tur' => 'hatali']);

        $this->calistir();
        $s = $this->satir($id);
        $this->assertSame([6, 1, 'patladi'], [(int) $s->durum, (int) $s->deneme, $s->hata]);

        $this->calistir();
        $this->assertSame([6, 2], [(int) $this->satir($id)->durum, (int) $this->satir($id)->deneme]);

        $this->calistir();
        $this->assertSame([2, 3], [(int) $this->satir($id)->durum, (int) $this->satir($id)->deneme]);

        $this->calistir();
        $this->assertSame(3, (int) $this->satir($id)->deneme);
    }

    public function test_tur_basina_en_cok_yirmi_kayit_sirayla(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->kayit();
        }

        $this->calistir();

        $this->assertCount(20, SahteIsleyici::$gelen);
        $this->assertSame(5, DB::table('v2_bildirim_kuyrugu')->where('durum', 6)->count());
        $idler = array_column(SahteIsleyici::$gelen, 'id');
        $this->assertSame($idler, array_values(collect($idler)->sort()->all()));
    }

    public function test_bes_dakikadan_eski_isleniyor_geri_alinir_yenisi_kalir(): void
    {
        $eski = $this->kayit(['durum' => 7, 'updated_at' => now()->subMinutes(6)]);
        $yeni = $this->kayit(['durum' => 7, 'updated_at' => now()->subMinutes(2)]);

        $this->calistir();

        $this->assertSame(1, (int) $this->satir($eski)->durum);
        $this->assertSame(7, (int) $this->satir($yeni)->durum);
    }

    public function test_eski_isleyicinin_satirlarina_dokunmaz(): void
    {
        $bekleyen = $this->kayit(['durum' => 0, 'updated_at' => now()->subHour()]);
        $isleniyor = $this->kayit(['durum' => 3, 'updated_at' => now()->subHour()]);

        $this->artisan('v2:bildirim-isle', ['--tek' => true])->assertSuccessful();

        $this->assertSame(0, (int) $this->satir($bekleyen)->durum);
        $this->assertSame(3, (int) $this->satir($isleniyor)->durum);
    }

    public function test_yeni_satir_bayrak_acikken_yeni_isleyiciye_kapaliyken_eskiye_yazilir(): void
    {
        BildirimKuyrugu::yaz(20, 'basarili', null, null);
        $this->assertSame(6, (int) DB::table('v2_bildirim_kuyrugu')->latest('id')->value('durum'));

        config(['bildirim_kuyrugu.isleyici_aktif' => false]);
        BildirimKuyrugu::yaz(20, 'basarili', null, null);
        $this->assertSame(0, (int) DB::table('v2_bildirim_kuyrugu')->latest('id')->value('durum'));
    }

    public function test_sms_kuyrugunun_durumlarina_dokunmaz(): void
    {
        $bekleyen = $this->kayit(['durum' => 4, 'updated_at' => now()->subHour()]);
        $isleniyor = $this->kayit(['durum' => 5, 'updated_at' => now()->subHour()]);

        $this->calistir();

        $this->assertSame(4, (int) $this->satir($bekleyen)->durum);
        $this->assertSame(5, (int) $this->satir($isleniyor)->durum);
        $this->assertSame([], SahteIsleyici::$gelen);
    }

    public function test_haritada_olmayan_tur_eski_hata_metniyle_duser(): void
    {
        $randevuId = (int) DB::table('randevular')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        $randevulu = $this->kayit(['tur' => 'yok_boyle', 'randevu_id' => $randevuId]);
        $randevusuz = $this->kayit(['tur' => 'yok_boyle']);

        $this->calistir();

        $this->assertSame('Bilinmeyen tur: yok_boyle', $this->satir($randevulu)->hata);
        $this->assertSame('Randevu bulunamadi: ', $this->satir($randevusuz)->hata);
    }

    public function test_harita_outbox_turlerinin_tamamini_kapsar(): void
    {
        $harita = (require base_path('config/bildirim_kuyrugu.php'))['harita'];

        foreach (['randevu_iptal', 'randevu_onay', 'randevu_kayit', 'randevu_tasindi', 'dogrulama_kodu', 'kvkk_kodu', 'seans_kullanim', 'ongorusme_kayit',
            'personel_sifre', 'yetki_degisti', 'personel_pasif', 'sifre_degisti', 'musteri_kayit', 'kara_liste_bildirim', 'ders_bildirim', 'whatsapp_mesaj',
            'sistem_bildirim', 'sms', 'form_link', 'bildirim_reklam_push', 'cark_duyuru', 'sms_toplu', 'kampanya_olustur', 'anket_link', 'anket_gonder',
            'google_takvim', 'saat_boslugu_kampanya'] as $tur) {
            $this->assertArrayHasKey($tur, $harita);
            $this->assertTrue(is_subclass_of($harita[$tur], BildirimIsleyici::class), $harita[$tur].' yazilmali');
        }
    }

    public function test_is_sync_ise_hemen_calisir(): void
    {
        config(['queue.default' => 'sync']);
        SahteIs::$calisti = 0;
        Bus::fake();

        app(IsDagitici::class)->calistir(new SahteIs);

        $this->assertSame(1, SahteIs::$calisti);
        Bus::assertNothingDispatched();
    }

    public function test_is_sync_degilse_hatirlatmalar_kuyruguna_gider_tek_deneme(): void
    {
        config(['queue.default' => 'database']);
        SahteIs::$calisti = 0;
        Bus::fake();

        app(IsDagitici::class)->calistir(new SahteIs);

        $this->assertSame(0, SahteIs::$calisti);
        Bus::assertDispatched(SahteIs::class, fn (SahteIs $is) => $is->queue === 'v2_bildirim' && $is->tries === 1);
    }
}

class SahteIsleyici implements BildirimIsleyici
{
    public static array $gelen = [];

    public function isle(object $kayit, array $payload): void
    {
        self::$gelen[] = ['id' => (int) $kayit->id, 'payload' => $payload];
    }
}

class HataliSahteIsleyici implements BildirimIsleyici
{
    public function isle(object $kayit, array $payload): void
    {
        throw new RuntimeException('patladi');
    }
}

class SahteIs extends KuyrukIsi
{
    public static int $calisti = 0;

    public function handle(): void
    {
        self::$calisti++;
    }
}
