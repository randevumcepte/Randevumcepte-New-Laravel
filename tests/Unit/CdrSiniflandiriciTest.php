<?php

namespace Tests\Unit;

use App\Domain\Santral\CdrSiniflandirici;
use App\Domain\Santral\SantralErisim;
use PHPUnit\Framework\TestCase;

class CdrSiniflandiriciTest extends TestCase
{
    private function cdr(array $alan): array
    {
        return $alan + ['calldate' => '2026-11-03 14:05:09', 'src' => '05321234567', 'dst' => '101', 'channel' => '', 'disposition' => 'ANSWERED', 'billsec' => 42, 'recording_path' => 'https://s/k.wav'];
    }

    public function test_giden_cevaplanan_ve_ulasilamayan(): void
    {
        $giden = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'from-internal', 'channel' => 'PJSIP/101-0000a1', 'dst' => '05321234567']));
        $this->assertSame(['giden', '2', 'giden', '101'], [$giden['yon'], $giden['durum'], $giden['kategori'], $giden['personel_dahili']]);
        $this->assertSame('https://s/k.wav', $giden['ses_kaydi']);
        $this->assertSame('2026-11-03 14:05:09', $giden['zaman']);

        $yok = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'from-internal', 'disposition' => 'BUSY']));
        $this->assertSame(['1', 'ulasilamadi'], [$yok['durum'], $yok['kategori']]);
        $this->assertNull($yok['ses_kaydi']);
    }

    public function test_dahili_dahili_arama_isaretlenir(): void
    {
        $s = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'from-internal', 'channel' => 'SIP/101-1', 'dst' => '102']), ['101', '102']);
        $this->assertTrue($s['dahili_arama']);
    }

    public function test_gelen_ve_cevapsiz(): void
    {
        $gelen = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'ext-local']));
        $this->assertSame(['gelen', '3', '101', '05321234567'], [$gelen['yon'], $gelen['durum'], $gelen['personel_dahili'], $gelen['karsi_numara']]);

        $cevapsiz = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'ext-local', 'disposition' => 'NO ANSWER']));
        $this->assertSame(['0', 'cevapsiz'], [$cevapsiz['durum'], $cevapsiz['kategori']]);
    }

    public function test_asistan_menuleri(): void
    {
        $beklenen = ['ana-menu' => ['4', 'cevapsiz'], 'yol-tarifi' => ['5', 'gelen'], 'kampanya-tanitimyeni' => ['6', 'gelen'],
            'yeni-randevu-menusu' => ['7', 'gelen'], 'randevu-guncelleme-menu' => ['8', 'gelen'], 'randevu-guncelleme-menusu' => ['8', 'gelen'],
            'randevu-iptal-menusu' => ['9', 'gelen']];
        foreach ($beklenen as $baglam => [$kod, $kategori]) {
            $s = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => $baglam]));
            $this->assertSame([$kod, $kategori, true], [$s['durum'], $s['kategori'], $s['asistan']], $baglam);
            $this->assertNull($s['ses_kaydi'], $baglam);
        }
    }

    public function test_rapora_alinmayanlar(): void
    {
        $this->assertNull(CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'from-trunk-custom'])));
        $this->assertNull(CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'from-internal', 'accountcode' => 'cmerkezi'])));
        $this->assertNull(CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'ext-local', 'userfield' => 'cmerkezi'])));
    }

    public function test_son10_format_bagimsiz(): void
    {
        foreach (['05321234567', '905321234567', '+90 532 123 45 67', '5321234567', '00905321234567'] as $n) {
            $this->assertSame('5321234567', CdrSiniflandirici::son10($n), $n);
        }
        $this->assertSame('', CdrSiniflandirici::son10('101'));
    }

    public function test_personel_yalniz_kendi_dahilisini_ve_sonucsuzu_gorur(): void
    {
        $personel = new SantralErisim(false, ['101', '9101'], false);
        $benim = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'ext-local', 'dst' => '9101']));
        $baskasi = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'ext-local', 'dst' => '102']));
        $sonucsuz = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'ana-menu', 'dst' => 's']));
        $banaDahili = CdrSiniflandirici::siniflandir($this->cdr(['dcontext' => 'from-internal', 'channel' => 'SIP/102-1', 'dst' => '101']), ['101', '102']);

        $this->assertTrue($personel->gorebilirMi($benim));
        $this->assertFalse($personel->gorebilirMi($baskasi));
        $this->assertTrue($personel->gorebilirMi($sonucsuz));
        $this->assertTrue($personel->gorebilirMi($banaDahili));
        $this->assertSame('101', $personel->dakikaDahilisi());

        $dahilisiz = new SantralErisim(false, [], false);
        $this->assertFalse($dahilisiz->gorebilirMi($benim));
        $this->assertNull($dahilisiz->dakikaDahilisi());

        $yonetici = new SantralErisim(true, [], true);
        $this->assertTrue($yonetici->gorebilirMi($baskasi));
        $this->assertNull($yonetici->dakikaDahilisi());
    }
}
