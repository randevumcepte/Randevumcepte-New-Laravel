<?php

namespace Tests\Unit\Domain\Randevu;

use App\Domain\Randevu\RandevuDurumu;
use App\Domain\Randevu\RandevuZamani;
use App\Exceptions\GecersizIslemException;
use PHPUnit\Framework\TestCase;

/**
 * Randevular listesi filtreleri — eski randevular_liste.blade secenekleriyle ayni araliklar.
 */
class RandevuZamaniTest extends TestCase
{
    private const BUGUN = '2026-11-03';

    private function aralik(string $donem): array
    {
        $d = RandevuZamani::coz($donem, null, null, self::BUGUN);

        return [$d['baslangic'], $d['bitis']];
    }

    public function test_ileri_ve_takvim_donemleri(): void
    {
        $this->assertSame(['2026-11-03', '2026-11-03'], $this->aralik('bugun'));
        $this->assertSame(['2026-11-04', '2026-11-04'], $this->aralik('yarin'));
        $this->assertSame(['2026-11-01', '2026-11-30'], $this->aralik('ay'));
        $this->assertSame(['2026-12-01', '2026-12-31'], $this->aralik('gelecek_ay'));
        $this->assertSame(['2026-01-01', '2026-12-31'], $this->aralik('yil'));
        $this->assertSame(['2027-01-01', '2027-12-31'], $this->aralik('gelecek_yil'));
    }

    public function test_varsayilan_bugun_tumu_null_ozel_aralik(): void
    {
        $this->assertSame('bugun', RandevuZamani::coz(null, null, null, self::BUGUN)['donem']);
        $this->assertNull(RandevuZamani::coz('tumu', null, null, self::BUGUN));
        $d = RandevuZamani::coz('ozel', '2026-05-20', '2026-05-10', self::BUGUN);
        $this->assertSame(['ozel', '2026-05-10', '2026-05-20', 'Özel Aralık'], [$d['donem'], $d['baslangic'], $d['bitis'], $d['etiket']]);
    }

    public function test_gecersiz_donem_422(): void
    {
        $this->expectException(GecersizIslemException::class);
        RandevuZamani::coz('hafta', null, null, self::BUGUN);
    }

    public function test_durum_filtresi_eski_secenekler(): void
    {
        $this->assertSame([1, 1], RandevuDurumu::filtre('geldi'));
        $this->assertSame([1, 0], RandevuDurumu::filtre('gelmedi'));
        $this->assertSame([0, null], RandevuDurumu::filtre('beklemede'));
        $this->assertSame([1, null], RandevuDurumu::filtre('onayli'));
        $this->assertSame([2, null], RandevuDurumu::filtre('iptal'));
        $this->assertSame([3, null], RandevuDurumu::filtre('musteri_iptal'));
        $this->assertNull(RandevuDurumu::filtre(''));
        $this->assertNull(RandevuDurumu::filtre('x'));
    }
}
