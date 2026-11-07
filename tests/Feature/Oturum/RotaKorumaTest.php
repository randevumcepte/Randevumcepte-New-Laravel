<?php

namespace Tests\Feature\Oturum;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Her v2 ucu kimlik ister; yalniz bilincli kimliksiz uclar bu listededir. Yeni bir kimliksiz uc
 * eklemek testin bu listesini degistirmeyi gerektirir.
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
