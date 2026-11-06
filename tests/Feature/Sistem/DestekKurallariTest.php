<?php

namespace Tests\Feature\Sistem;

use App\Domain\SistemYonetim\DuyuruHedefi;
use App\Domain\SistemYonetim\DuyuruHedefTipi;
use App\Domain\SistemYonetim\KdvHesabi;
use App\Domain\SistemYonetim\ManuelOdemeSatirlari;
use App\Domain\SistemYonetim\SiparisOdemeDurumu;
use App\Domain\SistemYonetim\TalepDurumu;
use App\Domain\SistemYonetim\TalepGecisi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Saf kurallar: veritabani ve uygulama gerekmez. */
class DestekKurallariTest extends TestCase
{
    private const SIMDI = '2026-11-06 12:00:00';

    public static function tutarlar(): array
    {
        return [
            ['1.500,50', 1500.5], ['1500,50', 1500.5], ['1.500', 1500.0], ['1.234.567,8', 1234567.8], ['100', 100.0], [' 12,5 ', 12.5],
            [99, 99.0], [12.345, 12.35],
            ['1500.50', null], ['1.50', null], ['12,345', null], ['abc', null], ['', null], ['0', null], ['0,00', null], ['-5', null], [0, null], [null, null], [['1'], null],
        ];
    }

    #[DataProvider('tutarlar')]
    public function test_tutar_ayristirma(mixed $ham, ?float $beklenen): void
    {
        $this->assertSame($beklenen, ManuelOdemeSatirlari::tutar($ham));
    }

    public function test_satirlar_bosu_atlar_bozugu_sira_noyla_bildirir(): void
    {
        $sonuc = ManuelOdemeSatirlari::ayristir([
            ['ad' => 'A', 'adet' => 3, 'tutar' => '10'],
            ['ad' => '', 'tutar' => ''],
            ['ad' => 'B', 'tutar' => 'yanlis'],
            ['ad' => '', 'tutar' => '5'],
        ]);

        $this->assertSame([['ad' => 'A', 'adet' => 3, 'tutar' => 10.0]], $sonuc['satirlar']);
        $this->assertSame([3, 4], $sonuc['hatali']);
        $this->assertSame(30.0, ManuelOdemeSatirlari::toplam($sonuc['satirlar']));
    }

    public function test_yanit_gecisi(): void
    {
        $this->assertSame(['ilk_yanit_tarihi' => self::SIMDI, 'durum' => 'islemde'], TalepGecisi::yanit('acik', null, self::SIMDI));
        $this->assertSame([], TalepGecisi::yanit('bekliyor', '2020-01-01 00:00:00', self::SIMDI));
    }

    public function test_durum_gecisi_damgayi_ezmez(): void
    {
        $this->assertSame(['durum' => 'cozumlendi', 'cozumlenme_tarihi' => self::SIMDI], TalepGecisi::durum(TalepDurumu::Cozumlendi, null, null, self::SIMDI));
        $this->assertSame(['durum' => 'cozumlendi'], TalepGecisi::durum(TalepDurumu::Cozumlendi, '2020-01-01', null, self::SIMDI));
        $this->assertSame(['durum' => 'kapali', 'kapanis_tarihi' => self::SIMDI], TalepGecisi::durum(TalepDurumu::Kapali, null, null, self::SIMDI));
        $this->assertSame(['durum' => 'acik'], TalepGecisi::durum(TalepDurumu::Acik, '2020-01-01', '2020-01-02', self::SIMDI));
    }

    public function test_duyuru_hedefi(): void
    {
        $this->assertSame([], DuyuruHedefi::idler(DuyuruHedefTipi::Hepsi, [1], [2]));
        $this->assertSame([1, 2], DuyuruHedefi::idler(DuyuruHedefTipi::Secili, ['1', 2, 2, 0, -3], [9]));
        $this->assertSame([9], DuyuruHedefi::idler(DuyuruHedefTipi::Il, [1], [9]));
        $this->assertSame([1, 2], DuyuruHedefi::coz('[1,"2"]'));
        $this->assertSame([], DuyuruHedefi::coz('bozuk'));
        $this->assertSame([], DuyuruHedefi::coz(null));
    }

    public function test_kdv_dahil_tutardan_ayrilir(): void
    {
        $this->assertSame(['matrah' => 100.0, 'kdv' => 20.0], KdvHesabi::dahilTutardan(120.0));
        $this->assertSame(20, KdvHesabi::yuzde());
    }

    public function test_tanimsiz_kod_rozette_ham_gorunur(): void
    {
        $this->assertSame(['kod' => 'eski', 'etiket' => 'eski', 'renk' => 'muted'], TalepDurumu::rozet('eski'));
        $this->assertSame('—', TalepDurumu::rozet(null)['etiket']);
        $this->assertSame('—', SiparisOdemeDurumu::rozet(null)['etiket']);
        $this->assertSame('Ödendi', SiparisOdemeDurumu::rozet(1)['etiket']);
        $this->assertSame(9, SiparisOdemeDurumu::rozet(9)['kod']);
    }
}
