<?php

namespace Tests\Unit\Domain\Rapor;

use App\Domain\Rapor\OdemeKovasi;
use App\Domain\Rapor\RaporDonemi;
use App\Exceptions\GecersizIslemException;
use PHPUnit\Framework\TestCase;

/** RaporDonemi (tum rapor uclarinin donem sozlesmesi) + OdemeKovasi — DB YOK. */
class RaporDonemiTest extends TestCase
{
    private const BUGUN = '2026-11-03'; // Sali

    private function aralik(string $donem): array
    {
        $d = RaporDonemi::coz($donem, null, null, 'ay', self::BUGUN);

        return [$d->baslangic, $d->bitis];
    }

    public function test_hazir_donemler_takvim_sinirlarinda(): void
    {
        $this->assertSame(['2026-11-03', '2026-11-03'], $this->aralik('bugun'));
        $this->assertSame(['2026-11-02', '2026-11-02'], $this->aralik('dun'));
        $this->assertSame(['2026-11-02', '2026-11-08'], $this->aralik('hafta'));
        $this->assertSame(['2026-11-01', '2026-11-30'], $this->aralik('ay'));
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->aralik('gecen_ay'));
        $this->assertSame(['2026-01-01', '2026-12-31'], $this->aralik('yil'));
        $this->assertSame(['2025-01-01', '2025-12-31'], $this->aralik('gecen_yil'));
    }

    public function test_bos_donem_varsayilani_kullanir(): void
    {
        $d = RaporDonemi::coz('', null, null, 'bugun', self::BUGUN);
        $this->assertSame('bugun', $d->anahtar);
        $this->assertTrue($d->tekGun());
        $this->assertSame('2026-11-03 00:00:00', $d->basSaat());
        $this->assertSame('2026-11-03 23:59:59', $d->bitSaat());
    }

    public function test_ozel_aralik_ters_sira_duzelir_bitis_bossa_tek_gun(): void
    {
        $d = RaporDonemi::coz('ozel', '2026-10-20', '2026-10-05');
        $this->assertSame(['2026-10-05', '2026-10-20'], [$d->baslangic, $d->bitis]);

        $tek = RaporDonemi::coz('ozel', '2026-10-20', '');
        $this->assertSame(['2026-10-20', '2026-10-20'], [$tek->baslangic, $tek->bitis]);
        $this->assertSame('Özel Aralık', $tek->dizi()['etiket']);
    }

    public function test_gecersiz_girdi_422(): void
    {
        $this->expectException(GecersizIslemException::class);
        RaporDonemi::coz('ozel', '2026-02-30', null);
    }

    public function test_bilinmeyen_donem_422(): void
    {
        $this->expectException(GecersizIslemException::class);
        RaporDonemi::coz('ceyrek', null, null);
    }

    public function test_odeme_kovasi_id_esasli_ve_toplama(): void
    {
        $this->assertSame(OdemeKovasi::Nakit, OdemeKovasi::icin(1));
        $this->assertSame(OdemeKovasi::Online, OdemeKovasi::icin(4));
        $this->assertSame(OdemeKovasi::Diger, OdemeKovasi::icin(5));   // senet
        $this->assertSame(OdemeKovasi::Diger, OdemeKovasi::icin(null));

        $k = OdemeKovasi::topla([
            ['odeme_yontemi_id' => 1, 'toplam' => 100.10], ['odeme_yontemi_id' => 1, 'toplam' => 0.2],
            (object) ['odeme_yontemi_id' => 2, 'toplam' => '50'], ['odeme_yontemi_id' => null, 'toplam' => 7],
        ]);
        $this->assertSame(100.3, $k['nakit']);
        $this->assertSame(50.0, $k['kredikarti']);
        $this->assertSame(7.0, $k['diger']);
        $this->assertSame(0.0, $k['havale']);
    }
}
