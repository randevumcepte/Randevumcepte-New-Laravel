<?php

namespace Tests\Unit\Domain\Lisans;

use App\Domain\Lisans\LisansKurali;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Lisans gun kurali: bitis gununun sonuna kadar acik, ertesi gun 00:00'da kapali (Europe/Istanbul). */
class LisansKuraliTest extends TestCase
{
    private const TZ = 'Europe/Istanbul';

    private static function an(string $s, string $tz = self::TZ): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone($tz));
    }

    /** @return array<string, array{mixed, string, string, bool}> */
    public static function durumlar(): array
    {
        return [
            'bitis gunu sabah acik'             => ['2026-11-08', '2026-11-08 09:00:00', self::TZ, true],
            'bitis gunu 23:59:59 acik'          => ['2026-11-08', '2026-11-08 23:59:59', self::TZ, true],
            'ertesi gun 00:00:00 kapali'        => ['2026-11-08', '2026-11-09 00:00:00', self::TZ, false],
            'gecmis tarih kapali'               => ['2026-10-01', '2026-11-08 12:00:00', self::TZ, false],
            'gelecek tarih acik'                => ['2027-01-01', '2026-11-08 12:00:00', self::TZ, true],
            'datetime kolonu saat yok sayilir'  => ['2026-11-08 00:00:00', '2026-11-08 22:00:00', self::TZ, true],
            // Sunucu UTC: 21:30 UTC = Istanbul 00:30 (ertesi gun) → kapali; 20:59:59 UTC = 23:59:59 → acik.
            'UTC an Istanbul gece yarisi sonrasi' => ['2026-11-08', '2026-11-08 21:30:00', 'UTC', false],
            'UTC an Istanbul gun sonu oncesi'     => ['2026-11-08', '2026-11-08 20:59:59', 'UTC', true],
            'bos tarih kapali (aktif olmadi)'   => [null, '2026-11-08 12:00:00', self::TZ, false],
            'bos metin kapali'                  => ['', '2026-11-08 12:00:00', self::TZ, false],
            'sifir tarih kapali'                => ['0000-00-00', '2026-11-08 12:00:00', self::TZ, false],
            'gecersiz tarih kapali'             => ['2026-02-30', '2026-01-01 12:00:00', self::TZ, false],
        ];
    }

    #[Test]
    #[DataProvider('durumlar')]
    public function gun_sonu_ve_saat_dilimi(mixed $bitis, string $simdi, string $tz, bool $acik): void
    {
        $this->assertSame($acik, LisansKurali::acikMi($bitis, self::an($simdi, $tz), self::TZ));
    }

    #[Test]
    public function uzatilan_tarih_ayni_anda_acilir(): void
    {
        $simdi = self::an('2026-11-09 10:00:00');
        $this->assertFalse(LisansKurali::acikMi('2026-11-08', $simdi, self::TZ));
        $this->assertTrue(LisansKurali::acikMi('2027-11-08', $simdi, self::TZ));
    }

    #[Test]
    public function cevap_verisi_kod_ve_bitis_tasir(): void
    {
        $this->assertSame(
            ['kod' => 'lisans_suresi_doldu', 'salon_id' => 20, 'uyelik_bitis_tarihi' => '2026-11-08', 'aktif_olmadi' => false],
            LisansKurali::cevapVerisi(20, '2026-11-08 00:00:00'),
        );
        $this->assertTrue(LisansKurali::cevapVerisi(20, null)['aktif_olmadi']);
    }

    #[Test]
    public function mesaj_tarihi_gosterir_ya_da_aktif_olmadi_der(): void
    {
        $this->assertStringContainsString('08.11.2026', LisansKurali::mesaj('2026-11-08'));
        $this->assertStringContainsString('henüz aktif olmamıştır', LisansKurali::mesaj(null));
    }

    #[Test]
    public function kapali_salon_karari_bagli_olmayan_ve_salonsuz_istekte_tum_bagli_salonlara_bakar(): void
    {
        $simdi = self::an('2026-11-08 12:00:00');
        $bitis = [20 => '2026-11-07', 21 => '2027-01-01'];
        $karar = fn (array $bagli, array $istenen) => LisansKurali::kapaliSalon($bagli, $istenen, $bitis, $simdi, self::TZ);

        // Bagli istenen kapali → o salonla kapali.
        $this->assertSame(20, $karar([20, 21], [20])['salon_id']);
        // Bagli istenen acik → gecer.
        $this->assertNull($karar([20, 21], [21]));
        // Farkli alanlar: biri kapaliysa kapali.
        $this->assertSame(20, $karar([20, 21], [21, 20])['salon_id']);
        // Tek salon kapali: bagli olmayan salon_id ya da salonsuz istek → kapali, salon soylenmez.
        $this->assertSame(['kod' => 'lisans_suresi_doldu', 'salon_id' => null, 'uyelik_bitis_tarihi' => '2026-11-07', 'aktif_olmadi' => false], $karar([20], [999999]));
        $this->assertNull($karar([20], [])['salon_id']);
        // Acik bir bagli salon varken bagli olmayan / salonsuz istek gecer (kaydin salonu kapsam gecidinde denetlenir).
        $this->assertNull($karar([20, 21], [999999]));
        $this->assertNull($karar([20, 21], []));
        // Hic bagli salonu olmayan: kapi karismaz (403 kapsamindir).
        $this->assertNull($karar([], [20]));
    }

    #[Test]
    public function en_gec_bitis(): void
    {
        $this->assertSame('2027-01-01', LisansKurali::enGecBitis(['2026-11-07', null, '2027-01-01 00:00:00', '0000-00-00']));
        $this->assertNull(LisansKurali::enGecBitis([null, '']));
    }
}
