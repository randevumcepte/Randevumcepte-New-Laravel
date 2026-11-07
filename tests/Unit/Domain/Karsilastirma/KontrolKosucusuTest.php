<?php

namespace Tests\Unit\Domain\Karsilastirma;

use App\Domain\Karsilastirma\KontrolKosucusu as Kosucu;
use App\Domain\Karsilastirma\MobilTutarKontrolleri as Liste;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class KontrolKosucusuTest extends TestCase
{
    private const TOKEN = 'gizli-token-abc';

    private array $istekler = [];

    private function kosucu(array $cevaplar, array $degerler = ['salon' => '20'], array $tokenlar = ['isletme' => self::TOKEN]): Kosucu
    {
        return new Kosucu(function (string $yontem, string $adres, array $sorgu, array $govde, ?string $token) use (&$cevaplar) {
            $this->istekler[] = compact('yontem', 'adres', 'sorgu', 'govde', 'token');
            $c = array_shift($cevaplar);
            if ($c instanceof RuntimeException) {
                throw $c;
            }

            return $c;
        }, $degerler, 'https://eski/api/v1', 'https://yeni/api/v2', $tokenlar);
    }

    private function kontrol(array $ek = []): array
    {
        return $ek + [
            'ad' => 'Deneme',
            'eski' => ['POST', '/rapor/{salon}', ['page' => 1], ['id' => '{salon}']],
            'yeni' => ['/rapor', ['salon_id' => '{salon}', 'mobil' => 1]],
            'alanlar' => ['tutar'],
        ];
    }

    private function zarf(array $veri): string
    {
        return json_encode(['basarili' => true, 'veri' => $veri, 'mesaj' => null]);
    }

    public function test_esit_cevap_ve_istek_bicimi(): void
    {
        $s = $this->kosucu([[200, '{"tutar":"10.00"}'], [200, $this->zarf(['tutar' => 10])]])->calistir($this->kontrol());

        $this->assertSame('esit', $s['durum']);
        $this->assertSame(['yontem' => 'POST', 'adres' => 'https://eski/api/v1/rapor/20', 'sorgu' => ['page' => 1], 'govde' => ['id' => '20'], 'token' => self::TOKEN], $this->istekler[0]);
        $this->assertSame(['yontem' => 'GET', 'adres' => 'https://yeni/api/v2/rapor', 'sorgu' => ['salon_id' => '20', 'mobil' => 1], 'govde' => [], 'token' => self::TOKEN], $this->istekler[1]);
    }

    public function test_tutar_farki_yazilir(): void
    {
        $s = $this->kosucu([[200, '{"tutar":10}'], [200, $this->zarf(['tutar' => 11])]])->calistir($this->kontrol());

        $this->assertSame('fark', $s['durum']);
        $this->assertSame(['tutar: eski 10 yeni 11'], $s['farklar']);
    }

    public function test_http_hatasi_ve_bozuk_cevap_fark_sayilir(): void
    {
        $s = $this->kosucu([[500, ''], [403, '{}']])->calistir($this->kontrol());
        $this->assertSame(['eski uc HTTP 500', 'yeni uc HTTP 403'], $s['farklar']);

        $this->assertSame(['eski uc cevabi JSON degil'], $this->kosucu([[200, '<html>'], [200, $this->zarf([])]])->calistir($this->kontrol())['farklar']);
        $this->assertSame(['yeni uc basarili zarf dondurmedi'], $this->kosucu([[200, '{}'], [200, '<html>']])->calistir($this->kontrol())['farklar']);
    }

    public function test_eksik_deger_ve_eksik_token_kontrolu_atlar_istek_atmaz(): void
    {
        $s = $this->kosucu([])->calistir($this->kontrol(['yeni' => ['/rapor/{musteri_id}', []]]));
        $this->assertSame('atlandi', $s['durum']);
        $this->assertSame('deger gerekli: musteri_id', $s['neden']);

        $s = $this->kosucu([])->calistir($this->kontrol(['token' => Liste::TOKEN_MUSTERI]));
        $this->assertSame('atlandi', $s['durum']);
        $this->assertSame([], $this->istekler);
    }

    public function test_yer_tutucu_bicimleri(): void
    {
        $this->kosucu([[200, '{}'], [200, $this->zarf([])]], ['salon' => '20', 'p' => '7'])->calistir($this->kontrol([
            'eski' => ['POST', '/x', [], ['a' => '{yok?}', 'b' => '{yok|p}', 'c' => '{yok~}', 'd' => '{p~}']],
            'yeni' => ['/y/{p}', ['e' => '{yok~}']],
        ]));

        $this->assertSame(['a' => '', 'b' => '7', 'd' => '7'], $this->istekler[0]['govde']);
        $this->assertSame('https://yeni/api/v2/y/7', $this->istekler[1]['adres']);
        $this->assertSame([], $this->istekler[1]['sorgu']);
    }

    public function test_yazma_yolu_cagrilmaz(): void
    {
        $this->expectException(LogicException::class);
        try {
            $this->kosucu([])->calistir($this->kontrol(['eski' => ['POST', '/tahsilatekle', [], []]]));
        } finally {
            $this->assertSame([], $this->istekler);
        }
    }

    public function test_hata_metninde_token_gorunmez(): void
    {
        $s = $this->kosucu([new RuntimeException('baglanti koptu Bearer '.self::TOKEN)])->calistir($this->kontrol());

        $this->assertSame('fark', $s['durum']);
        $this->assertStringNotContainsString(self::TOKEN, implode(' ', $s['farklar']));
        $this->assertSame(['istek hatasi: baglanti koptu Bearer ***'], $s['farklar']);
    }

    public function test_pdf_govdesi_cozulmez_ve_elle_bakilir_olarak_doner(): void
    {
        $pdf = $this->kontrol(['pdf' => true, 'eski' => ['GET', '/p']]);

        $this->assertSame('elle', $this->kosucu([[200, '%PDF-1'], [200, '%PDF-1']])->calistir($pdf)['durum']);
        $this->assertSame(['yeni uc PDF dondurmedi'], $this->kosucu([[200, '%PDF-1'], [200, '{}']])->calistir($pdf)['farklar']);
    }

    public function test_devreden_aylar_ve_musteri_turu_kokleri(): void
    {
        $k = fn (string $ad) => array_values(array_filter(Liste::hepsi(), fn ($x) => $x['ad'] === $ad))[0];
        $degerler = ['salon' => '20', 'yil' => '2026', 'musteri_id' => '5'];

        $s = $this->kosucu([[200, '{"success":true,"data":[{"ay":1,"tahsilatlar":"5.00"}]}'], [200, json_encode(['basarili' => true, 'veri' => [['ay' => 1, 'tahsilatlar' => 5]]])]], $degerler)
            ->calistir($k('Devreden aylar'));
        $this->assertSame('esit', $s['durum']);

        $s = $this->kosucu([[200, '2'], [200, $this->zarf(['tur' => 2])]], $degerler)->calistir($k('Musteri turu'));
        $this->assertSame('esit', $s['durum']);
    }
}
