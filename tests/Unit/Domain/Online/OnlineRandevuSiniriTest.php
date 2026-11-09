<?php

namespace Tests\Unit\Domain\Online;

use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Services\Online\BotKapisi;
use App\Services\Online\OnlineRandevuSiniri;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Online randevu sinirlari (eski randevuonaylaDirekt): musteri salon basina gunde 3, web bot kapisi; IP saatte 5
 * yalniz kimliksiz / misafir yolda (kimlikli musteri isteginde IP siniri yok).
 */
class OnlineRandevuSiniriTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'online.randevu_ip_saatlik' => 5, 'online.randevu_musteri_gunluk' => 3, 'online.kayit_min_saniye' => 3]);
        foreach (['1.1.1.1', '2.2.2.2'] as $ip) {
            RateLimiter::clear(OnlineRandevuSiniri::ipAnahtari($ip));
        }
        foreach ([10, 20] as $salon) {
            RateLimiter::clear(OnlineRandevuSiniri::musteriAnahtari($salon, 7));
        }
    }

    public function test_bot_kurali(): void
    {
        $this->assertTrue(BotKapisi::botMu(['_hp' => 'x'], 3));
        $this->assertTrue(BotKapisi::botMu(['form_sure_sn' => 1], 3));
        $this->assertFalse(BotKapisi::botMu(['form_sure_sn' => 3, '_hp' => ''], 3));
        $this->assertFalse(BotKapisi::botMu([], 3), 'uygulama sure gondermez');
    }

    public function test_web_formunda_bot_reddedilir_uygulamada_sure_aranmaz(): void
    {
        $s = new OnlineRandevuSiniri();
        $s->denetle(10, 7, '1.1.1.1', ['kaynak' => 'uygulama', 'form_sure_sn' => 0]);

        $this->expectException(GecersizIslemException::class);
        $s->denetle(10, 7, '1.1.1.1', ['kaynak' => 'web', 'form_sure_sn' => 1]);
    }

    public function test_musteri_salon_basina_gunde_uc(): void
    {
        $s = new OnlineRandevuSiniri();
        foreach (range(1, 3) as $i) {
            $s->denetle(10, 7, null, ['kaynak' => 'uygulama']);
            $s->say(10, 7, null);
        }
        $s->denetle(20, 7, null, ['kaynak' => 'uygulama']); // baska salon ayri sayilir

        try {
            $s->denetle(10, 7, null, ['kaynak' => 'uygulama']);
            $this->fail('dorduncu randevu reddedilmeliydi');
        } catch (CokFazlaIstekException $e) {
            $this->assertSame(429, $e->durumKodu);
            $this->assertStringContainsString('Yarın tekrar deneyin', $e->getMessage());
        }
    }

    public function test_kimlikli_musteride_ip_siniri_yok(): void
    {
        // Ayni IP'yi paylasan (NAT / ters vekil) kimlikli musteriler birbirini kilitlemez; IP sayaci da artmaz.
        $s = new OnlineRandevuSiniri();
        foreach (range(1, 8) as $musteri) {
            $s->denetle(10, 100 + $musteri, null, ['kaynak' => 'uygulama']);
            $s->say(10, 100 + $musteri, null);
        }
        $this->assertSame(0, RateLimiter::attempts(OnlineRandevuSiniri::ipAnahtari('1.1.1.1')));
        $s->denetle(10, 7, null, ['kaynak' => 'web', 'form_sure_sn' => 5]);
        $this->assertTrue(true);
    }

    public function test_misafir_yolda_ip_saatte_bes(): void
    {
        $s = new OnlineRandevuSiniri();
        foreach (range(1, 5) as $musteri) {
            $s->say(10, 100 + $musteri, '1.1.1.1');
        }

        $this->expectException(CokFazlaIstekException::class);
        $s->denetle(10, 7, '1.1.1.1', ['kaynak' => 'uygulama']);
    }
}
