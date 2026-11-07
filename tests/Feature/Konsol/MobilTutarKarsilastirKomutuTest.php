<?php

namespace Tests\Feature\Konsol;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sunucuya gitmez: iki uc da sahte. */
class MobilTutarKarsilastirKomutuTest extends TestCase
{
    private const TOKEN = 'gizli-token-abc';

    private function calistir(array $parametre = []): array
    {
        $kod = Artisan::call('mobil:tutar-karsilastir', ['salon_id' => 20, '--token' => self::TOKEN, '--sunucu' => 'https://yeni.test', '--kontrol' => 'Kasa ozeti', '--bekle' => 0] + $parametre);

        return [$kod, Artisan::output()];
    }

    private function sahte(array $eski, array $yeni): void
    {
        Http::fake([
            'app.randevumcepte.com.tr/*' => Http::response($eski),
            'yeni.test/*' => Http::response(['basarili' => true, 'veri' => $yeni, 'mesaj' => null]),
        ]);
    }

    public function test_esitse_cikis_sifir_token_yazilmaz_ve_istekler_salt_okunur(): void
    {
        $tutar = ['toplamgelir' => '10.00', 'toplamgider' => 1, 'toplamKasa' => 9, 'toplamCiro' => 10];
        $this->sahte($tutar, ['toplamgelir' => 10] + $tutar);

        [$kod, $cikti] = $this->calistir(['--bas' => '2026-10-01', '--bit' => '2026-10-31']);

        $this->assertSame(0, $kod);
        $this->assertStringContainsString('ESIT  Kasa ozeti', $cikti);
        $this->assertStringNotContainsString(self::TOKEN, $cikti);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/api/v1/kasaraporu/20') && $r['tarih1'] === '2026-10-01');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'yeni.test/api/v2/kasa/ozet') && str_contains($r->url(), 'baslangic=2026-10-01'));
    }

    public function test_fark_varsa_cikis_bir_ve_fark_yazilir(): void
    {
        $this->sahte(['toplamgelir' => 10], ['toplamgelir' => 11]);

        [$kod, $cikti] = $this->calistir();

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('toplamgelir: eski 10 yeni 11', $cikti);
    }

    public function test_token_yoksa_istek_atilmaz(): void
    {
        Http::fake();
        putenv('MOBIL_KARSILASTIR_TOKEN');

        $kod = Artisan::call('mobil:tutar-karsilastir', ['salon_id' => 20, '--sunucu' => 'https://yeni.test']);

        $this->assertSame(2, $kod);
        Http::assertNothingSent();
    }

    public function test_token_ortamdan_okunur(): void
    {
        $this->sahte(['toplamgelir' => 1], ['toplamgelir' => 1]);
        putenv('MOBIL_KARSILASTIR_TOKEN=ortam-token');

        try {
            $kod = Artisan::call('mobil:tutar-karsilastir', ['salon_id' => 20, '--sunucu' => 'https://yeni.test', '--kontrol' => 'Kasa ozeti', '--bekle' => 0]);
        } finally {
            putenv('MOBIL_KARSILASTIR_TOKEN');
        }

        $this->assertSame(0, $kod);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ortam-token'));
        $this->assertStringNotContainsString('ortam-token', Artisan::output());
    }

    public function test_gecersiz_tarih_reddedilir(): void
    {
        Http::fake();

        [$kod] = $this->calistir(['--bas' => '01.10.2026']);

        $this->assertSame(2, $kod);
        Http::assertNothingSent();
    }
}
