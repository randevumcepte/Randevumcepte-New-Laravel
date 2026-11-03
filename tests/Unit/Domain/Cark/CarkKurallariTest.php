<?php

namespace Tests\Unit\Domain\Cark;

use App\Domain\Cark\DilimSeti;
use App\Domain\Cark\DilimTipi;
use App\Domain\Cark\KuponDurumu;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Çarkıfelek saf kuralları (DB'siz): dilim seti (tam bir kazanan, 6-12 dilim, tip/değer/birim
 * normalleştirme — eski web carkdilimekle + mobil carkDilimKaydet ile aynı), ödül metni ve kupon
 * durumu (eski kazananlar filtresi / doğrulama rozeti).
 */
class CarkKurallariTest extends TestCase
{
    private const RENK = ['#111111', '#222222'];

    private function dilimler(int $n, int $kazanan = 0): array
    {
        return array_map(fn ($i) => ['name' => "D$i", 'probability' => $i === $kazanan ? 100 : 0, 'tip' => 'puan', 'deger' => 10], range(0, $n - 1));
    }

    public function test_gecerli_set_normallesir(): void
    {
        $r = DilimSeti::olustur($this->dilimler(6, 2), 6, 12, self::RENK);
        $this->assertCount(6, $r);
        $this->assertSame(100, $r[2]['probability']);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($r, 'sira'));
    }

    public function test_dilim_sayisi_sinirlari(): void
    {
        foreach ([5, 13] as $n) {
            try {
                DilimSeti::olustur($this->dilimler($n), 6, 12, self::RENK);
                $this->fail("$n dilim kabul edilmemeliydi");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($n < 6 ? 'En az 6' : 'En fazla 12', $e->getMessage());
            }
        }
    }

    public function test_tam_olarak_bir_kazanan_sart(): void
    {
        $iki = $this->dilimler(6);
        $iki[1]['probability'] = 100;
        $yok = $this->dilimler(6);
        $yok[0]['probability'] = 0;
        foreach ([$iki, $yok] as $set) {
            try {
                DilimSeti::olustur($set, 6, 12, self::RENK);
                $this->fail('kazanan kuralı uygulanmadı');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_tip_deger_birim_ve_renk_normallestirme(): void
    {
        $set = $this->dilimler(6);
        $set[2] = ['name' => '', 'probability' => 0, 'tip' => 'bilinmeyen', 'deger' => 5, 'indirim_tipi' => 'tutar', 'color' => 'red;}'];
        $set[3] = ['probability' => '0', 'tip' => 'hizmet_indirimi', 'deger' => '150', 'indirim_tipi' => 'tutar', 'color' => '#ABC'];
        $r = DilimSeti::olustur($set, 6, 12, self::RENK);

        $this->assertSame(['bos', null, 'yuzde', 'Ödül 3', '#111111', 0], [$r[2]['tip'], $r[2]['deger'], $r[2]['indirim_tipi'], $r[2]['name'], $r[2]['color'], $r[2]['kupon_mu']]);
        $this->assertSame([150.0, 'tutar', '#ABC', 1], [$r[3]['deger'], $r[3]['indirim_tipi'], $r[3]['color'], $r[3]['kupon_mu']]);
        $this->assertSame('yuzde', DilimTipi::birim('puan', 'tutar'));
    }

    public function test_odul_metni(): void
    {
        $this->assertSame('%20 Hizmet İnd.', DilimTipi::odulMetni('hizmet_indirimi', 20.0, null));
        $this->assertSame('100 Puan', DilimTipi::odulMetni('puan', 100.0, null));
        $this->assertSame('12.5₺ Ürün İnd.', DilimTipi::odulMetni('urun_indirimi', 12.5, 'tutar'));
        $this->assertSame('Tekrar Dene', DilimTipi::odulMetni('tekrar_dene', null, null));
    }

    public function test_kupon_durumu(): void
    {
        $bugun = '2026-11-03';
        $this->assertSame(KuponDurumu::KULLANILDI, KuponDurumu::hesapla(true, '2020-01-01', $bugun));
        $this->assertSame(KuponDurumu::SURE_DOLDU, KuponDurumu::hesapla(false, '2026-11-02', $bugun));
        $this->assertSame(KuponDurumu::GECERLI, KuponDurumu::hesapla(false, '2026-11-03 00:00:00', $bugun));
        $this->assertSame(KuponDurumu::GECERLI, KuponDurumu::hesapla(false, null, $bugun));
        $this->assertSame(KuponDurumu::TUMU, KuponDurumu::filtre('gecersiz'));
    }
}
