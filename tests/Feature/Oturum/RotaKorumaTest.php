<?php

namespace Tests\Feature\Oturum;

use App\Providers\AppServiceProvider;
use Illuminate\Routing\Route as Rota;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Her v2 ucu kimlik ister; yalniz bilincli kimliksiz uclar bu listededir. Yeni bir kimliksiz uc
 * eklemek testin bu listesini degistirmeyi gerektirir. Kimlikli uc DOGRU guard'dadir (isletme / musteri /
 * sistem token'lari birbirinin ucuna giremez); her uc genel istek sinirindadir, kimliksiz yazma uclari ayrica
 * kendi siki sinirinda ya da imzalidir.
 */
class RotaKorumaTest extends TestCase
{
    private const KIMLIKSIZ = [
        'api/v2/health',
        'api/v2/telefon/ulkeler',
        'api/v2/vitrin*',
        'api/v2/musteri-oturum/*',
        'api/v2/oturum/giris',
        'api/v2/oturum/kod-iste',
        'api/v2/oturum/kod-dogrula',
        'api/v2/oturum/sifre-kodu',
        'api/v2/oturum/sifre-sifirla',
        'api/v2/deneme/*',
        'api/v2/sistem/oturum/giris',
        'api/v2/ses/*',
        // Harici tarayicida acilir, token tasiyamaz: imzali ve kisa omurlu link (asagidaki test imzayi dogrular).
        'api/v2/form/sablon/{id}/bos-pdf',
        // WebView token basligi gonderemez: imzali ve kisa omurlu adres (asagidaki test imzayi dogrular).
        'api/v2/whatsapp-kontor-sayfa',
        'api/v2/whatsapp-kontor-sayfa/talep',
    ];

    public function test_kimliksiz_uc_yalniz_izinli_listede_olur(): void
    {
        $korumasiz = [];
        foreach (Route::getRoutes() as $rota) {
            $yol = $rota->uri();
            if (! Str::startsWith($yol, 'api/v2/') || Str::is(self::KIMLIKSIZ, $yol)) {
                continue;
            }
            $korumali = collect($rota->gatherMiddleware())->contains(fn ($m) => is_string($m) && Str::startsWith($m, 'auth:'));
            if (! $korumali) {
                $korumasiz[] = implode('|', $rota->methods()).' '.$yol;
            }
        }

        $this->assertSame([], $korumasiz);
    }

    /** Uri deseni → beklenen TEK auth middleware'i (ilk eslesen). */
    private const GUARDLAR = [
        'api/v2/sistem/*'              => 'auth:sistemyonetim-api',
        'api/v2/musteri-uygulama/*'    => 'auth:api',
        'api/v2/oturum/cikis'          => 'auth:isletmeyonetim-api,api',
        'api/v2/oturum/yenile'         => 'auth:isletmeyonetim-api,api',
        'api/v2/*'                     => 'auth:isletmeyonetim-api',
    ];

    /** @return array<int, Rota> */
    private function v2Rotalari(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (Rota $r) => Str::startsWith($r->uri(), 'api/v2/')));
    }

    private static function etiket(Rota $r): string
    {
        return implode('|', $r->methods()).' '.$r->uri();
    }

    public function test_kimlikli_uc_dogru_guardda(): void
    {
        $yanlis = [];
        foreach ($this->v2Rotalari() as $rota) {
            if (Str::is(self::KIMLIKSIZ, $rota->uri())) {
                continue;
            }
            $beklenen = collect(self::GUARDLAR)->first(fn ($g, $desen) => Str::is($desen, $rota->uri()));
            $authlar = collect($rota->gatherMiddleware())->filter(fn ($m) => is_string($m) && Str::startsWith($m, 'auth:'))->values()->all();
            if ($authlar !== [$beklenen]) {
                $yanlis[] = self::etiket($rota).' → '.implode(',', $authlar).' (beklenen '.$beklenen.')';
            }
        }

        $this->assertSame([], $yanlis);
    }

    public function test_her_v2_ucu_genel_istek_sinirinda(): void
    {
        $sinirsiz = [];
        foreach ($this->v2Rotalari() as $rota) {
            if (! in_array('throttle:'.AppServiceProvider::GENEL_SINIR, $rota->gatherMiddleware(), true)) {
                $sinirsiz[] = self::etiket($rota);
            }
        }

        $this->assertSame([], $sinirsiz);
    }

    public function test_kimliksiz_yazma_ucu_kendi_siki_sinirinda_ya_da_imzali(): void
    {
        $acik = [];
        foreach ($this->v2Rotalari() as $rota) {
            if (! Str::is(self::KIMLIKSIZ, $rota->uri()) || $rota->methods() === ['GET', 'HEAD']) {
                continue;
            }
            $m = collect($rota->gatherMiddleware())->filter(fn ($x) => is_string($x));
            $sikiSinir = $m->contains(fn ($x) => Str::startsWith($x, 'throttle:') && $x !== 'throttle:'.AppServiceProvider::GENEL_SINIR);
            if (! $sikiSinir && ! $m->contains(fn ($x) => Str::startsWith($x, 'signed'))) {
                $acik[] = self::etiket($rota);
            }
        }

        $this->assertSame([], $acik);
    }

    public function test_kimliksiz_okuma_uclari_da_sinirli(): void
    {
        foreach ($this->v2Rotalari() as $rota) {
            if (Str::is(self::KIMLIKSIZ, $rota->uri())) {
                $this->assertTrue(collect($rota->gatherMiddleware())->contains(fn ($x) => is_string($x) && Str::startsWith($x, 'throttle:')), self::etiket($rota));
            }
        }
    }

    public function test_health_kimliksiz_ve_veritabani_acmaz(): void
    {
        // Acilamayan veritabani: uc DB'ye dokunsaydi 503 / 'veritabani' alani donerdi.
        config(['database.default' => 'kapali', 'database.connections.kapali' => ['driver' => 'sqlite', 'database' => '/olmayan/dizin/v2.sqlite', 'prefix' => '']]);

        $this->getJson('/api/v2/health')->assertOk()->assertJsonPath('durum', 'ok')->assertJsonMissingPath('veritabani');
    }

    public function test_bos_form_pdf_rotasi_imza_ister(): void
    {
        $rota = Route::getRoutes()->getByName('v2.form.bos-pdf');

        $this->assertNotNull($rota);
        $this->assertSame('api/v2/form/sablon/{id}/bos-pdf', $rota->uri());
        $this->assertContains('signed:relative', $rota->gatherMiddleware());
    }

    public function test_kontor_sayfasi_rotalari_imza_ister(): void
    {
        foreach (['v2.whatsapp-kontor.sayfa', 'v2.whatsapp-kontor.talep'] as $ad) {
            $rota = Route::getRoutes()->getByName($ad);

            $this->assertNotNull($rota, $ad);
            $this->assertContains('signed:relative', $rota->gatherMiddleware(), $ad);
        }
    }
}
