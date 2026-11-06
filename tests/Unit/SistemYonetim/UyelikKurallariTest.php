<?php

namespace Tests\Unit\SistemYonetim;

use App\Domain\SistemYonetim\UyelikKurallari;
use PHPUnit\Framework\TestCase;

class UyelikKurallariTest extends TestCase
{
    private const BUGUN = '2026-11-06';

    public function test_ileri_bitisin_uzerine_eklenir_kalan_sure_kaybolmaz(): void
    {
        $this->assertSame('2026-12-11', UyelikKurallari::gunUzat('2026-12-01', 10, self::BUGUN));
        $this->assertSame('2027-03-01', UyelikKurallari::ayUzat('2026-12-01', 3, self::BUGUN));
    }

    public function test_gecmis_bos_ve_sifir_tarih_bugunden_baslar(): void
    {
        foreach (['2026-01-01', self::BUGUN, null, '', '0000-00-00'] as $eski) {
            $this->assertSame('2026-11-13', UyelikKurallari::gunUzat($eski, 7, self::BUGUN));
        }
    }

    public function test_toplam_ay_hediye_dahil(): void
    {
        $this->assertSame(16, UyelikKurallari::toplamAy('yillik', 1, 4));
        $this->assertSame(3, UyelikKurallari::toplamAy('aylik', 3, 0));
    }

    public function test_ozet_renk_ve_metin(): void
    {
        $simdi = strtotime(self::BUGUN.' 12:00:00');

        $this->assertSame(['bitis' => null, 'kalan_gun' => null, 'renk' => 'muted', 'metin' => null], UyelikKurallari::ozet('0000-00-00', $simdi));
        $this->assertSame('bugün doluyor', UyelikKurallari::ozet(self::BUGUN, $simdi)['metin']);
        $this->assertSame('warning', UyelikKurallari::ozet(self::BUGUN, $simdi)['renk']);
        $this->assertSame('1 gün önce doldu', UyelikKurallari::ozet('2026-11-05', $simdi)['metin']);
        $this->assertSame('danger', UyelikKurallari::ozet('2026-11-05', $simdi)['renk']);
        $this->assertSame('success', UyelikKurallari::ozet('2026-12-30', $simdi)['renk']);
    }

    public function test_rozet_oncelik_sirasi(): void
    {
        $this->assertSame('askida', UyelikKurallari::rozet(true, true, 5)['kod']);
        $this->assertSame('demo', UyelikKurallari::rozet(false, true, -5)['kod']);
        $this->assertSame('aktif', UyelikKurallari::rozet(false, false, 0)['kod']);
        $this->assertSame('suresi_doldu', UyelikKurallari::rozet(false, false, -1)['kod']);
        $this->assertSame('suresi_doldu', UyelikKurallari::rozet(false, false, null)['kod']);
    }
}
