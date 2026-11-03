<?php

namespace Tests\Unit\Domain\Form;

use App\Domain\Form\SablonSorulari;
use PHPUnit\Framework\TestCase;

/** Şablon eleman kataloğu + sorular_json normalizasyonu — DB YOK. */
class SablonSorulariTest extends TestCase
{
    public function test_zorunluluk_tipten_turetilir(): void
    {
        $s = SablonSorulari::normalize([
            ['tip' => 'evet_hayir', 'soru' => 'Hamile misiniz?', 'zorunlu' => false],
            ['tip' => 'metin_ops', 'soru' => 'İlaçlar', 'zorunlu' => true],
        ]);
        $this->assertTrue($s[0]['zorunlu']);
        $this->assertFalse($s[1]['zorunlu']);
    }

    public function test_otomatik_tipte_soru_tip_adidir_bos_eleman_atilir(): void
    {
        $s = SablonSorulari::normalize(json_encode([
            ['tip' => 'ucret_bilgisi', 'soru' => ''],
            ['tip' => 'metin_blogu', 'soru' => '   '],
            ['tip' => 'bolum_basligi', 'soru' => 'GENEL'],
        ]));
        $this->assertCount(2, $s);
        $this->assertSame('ucret_bilgisi', $s[0]['soru']);
    }

    public function test_gecersiz_tip_ve_bos_liste_reddedilir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SablonSorulari::normalize([['tip' => 'script', 'soru' => 'x']]);
    }

    public function test_bos_liste_reddedilir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SablonSorulari::normalize([]);
    }

    public function test_katalogda_eski_tip_eklenemez(): void
    {
        $k = SablonSorulari::katalog();
        $bilgi = array_values(array_filter($k['tipler'], fn ($t) => $t['tip'] === 'bilgi_metni'))[0];
        $this->assertFalse($bilgi['eklenebilir']);
        $this->assertCount(3, $k['gruplar']);
    }

    public function test_bozuk_json_bos_liste(): void
    {
        $this->assertSame([], SablonSorulari::coz('{bozuk'));
        $this->assertSame([], SablonSorulari::coz(null));
    }
}
