<?php

namespace Tests\Unit\Domain\Ozet;

use App\Domain\Ozet\OzetEylemi;
use App\Domain\Ozet\OzetHesap;
use App\Domain\Ozet\OzetKarti;
use App\Domain\Ozet\OzetSekmesi;
use App\Domain\Rapor\RaporDonemi;
use App\Support\PanelMenu;
use App\Support\Yetki\YetkiSabitleri;
use PHPUnit\Framework\TestCase;

/** Ozet domain'i (saf hesap + kart/sekme/eylem tanimlari) + RaporDonemi kayan/kaydir — DB YOK. */
class OzetTest extends TestCase
{
    private const BUGUN = '2026-11-03';

    public function test_salon_doluluk_eski_formulle_ayni(): void
    {
        // 2 personel × 10 saat × (60/30) slot × 1 gun = 40 kapasite → 10 randevu %25
        $this->assertSame(25, OzetHesap::salonDoluluk(10, 2, 30, 1));
        // aralik 0 → alt sinir 15 dk (40 kapasite), ayarsiz (null) → 30 dk; personel 0 → 1; tavan 100
        $this->assertSame(3, OzetHesap::salonDoluluk(1, 0, 0, 1));
        $this->assertSame(5, OzetHesap::salonDoluluk(1, 0, null, 1));
        $this->assertSame(100, OzetHesap::salonDoluluk(999, 1, 5, 1));
    }

    public function test_personel_doluluk_ve_nps_ve_oranlar(): void
    {
        $this->assertSame(50, OzetHesap::personelDoluluk(8));
        $this->assertSame(100, OzetHesap::personelDoluluk(40));
        $this->assertSame(30, OzetHesap::nps(5, 3, 2));
        $this->assertNull(OzetHesap::nps(0, 0, 0));
        $this->assertSame(33.3, OzetHesap::oran(1, 3));
        $this->assertSame(0.0, OzetHesap::oran(5, 0));
        $this->assertSame(-50.0, OzetHesap::degisim(50, 100));
        $this->assertNull(OzetHesap::degisim(50, 0));
    }

    public function test_kayan_donemler_bugun_dahil(): void
    {
        $d = RaporDonemi::coz('son7', null, null, 'ay', self::BUGUN);
        $this->assertSame(['2026-10-28', '2026-11-03'], [$d->baslangic, $d->bitis]);
        $this->assertSame(7, $d->gunSayisi());
        $this->assertSame('Son 7 Gün', $d->dizi()['etiket']);
        $this->assertSame(['bugun', 'son30'], array_column(RaporDonemi::secenekListesi(['bugun', 'son30']), 'kod'));
        // rapor ekranlarinin varsayilan listesi degismedi
        $this->assertNotContains('son7', array_column(RaporDonemi::secenekListesi(), 'kod'));
    }

    public function test_kaydir_ay_tasmasi_yok_ve_birim_kayar(): void
    {
        $ay = RaporDonemi::coz('ay', null, null, 'ay', '2026-03-31');
        $onceki = $ay->kaydir(-1);
        $this->assertSame(['2026-02-01', '2026-02-28'], [$onceki->baslangic, $onceki->bitis]);
        $this->assertSame(['2025-09-01', '2025-09-30'], [$ay->kaydir(-6)->baslangic, $ay->kaydir(-6)->bitis]);

        $hafta = RaporDonemi::coz('hafta', null, null, 'ay', self::BUGUN);
        $this->assertSame(['2026-10-26', '2026-11-01'], [$hafta->kaydir(-1)->baslangic, $hafta->kaydir(-1)->bitis]);

        $yil = RaporDonemi::coz('yil', null, null, 'ay', self::BUGUN)->kaydir(-1);
        $this->assertSame(['2025-01-01', '2025-12-31'], [$yil->baslangic, $yil->bitis]);

        $son30 = RaporDonemi::coz('son30', null, null, 'ay', self::BUGUN)->kaydir(-1);
        $this->assertSame(['2026-09-05', '2026-10-04'], [$son30->baslangic, $son30->bitis]);
        $this->assertSame('Geçen Ay', $ay->oncekiEtiket());
    }

    public function test_tum_yetki_anahtarlari_tanimli(): void
    {
        $tanimli = array_column(YetkiSabitleri::tanimlar(), 'key');
        $kullanilan = array_merge(
            ...array_map(fn (OzetKarti $k) => $k->yetkiler(), OzetKarti::cases()),
            ...[array_map(fn (OzetSekmesi $s) => $s->yetki(), OzetSekmesi::cases())],
            ...[array_map(fn (OzetEylemi $e) => $e->yetki(), OzetEylemi::cases())],
        );
        foreach (array_unique($kullanilan) as $a) {
            $this->assertContains($a, $tanimli, "Tanimsiz yetki anahtari: {$a}");
        }
    }

    public function test_kart_donemleri_gecerli_ve_eylem_menuleri_var(): void
    {
        foreach (OzetKarti::cases() as $k) {
            $this->assertCount(count($k->donemler()), RaporDonemi::secenekListesi($k->donemler()), $k->value);
            if ($k->donemler()) {
                $this->assertContains($k->varsayilanDonem(), $k->donemler(), $k->value);
            }
        }
        foreach (OzetEylemi::cases() as $e) {
            $this->assertNotNull(PanelMenu::bul($e->menuSlug()), 'Menude yok: '.$e->menuSlug());
        }
        foreach (OzetSekmesi::cases() as $s) {
            $this->assertNotEmpty($s->kolonlar());
        }
    }
}
