<?php

namespace Tests\Unit\Domain\Online;

use App\Domain\Randevu\OnlineMusaitlik;
use App\Domain\Randevu\OnlineSaatKurali;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OnlineMusaitlikTest extends TestCase
{
    private const SAAT_10 = 600;

    private function takvim(array $ek = []): array
    {
        return $ek + ['salon' => [540, 1080], 'salon_mola' => [], 'pencere' => [], 'dolu' => [], 'personel_oda' => [], 'oda_dolu' => []];
    }

    private function kalem(array $adaylar, int $sure, array $odalar = []): array
    {
        return ['adaylar' => $adaylar, 'sure' => $sure, 'hizmet_odalari' => $odalar];
    }

    #[Test]
    public function bos_takvimde_kalemler_ardisik_yerlesir(): void
    {
        $y = OnlineMusaitlik::yerlestir([$this->kalem([1], 30), $this->kalem([2], 60)], self::SAAT_10, $this->takvim());

        $this->assertSame([600, 630], [$y[0]['bas'], $y[0]['bit']]);
        $this->assertSame([630, 690, 2], [$y[1]['bas'], $y[1]['bit'], $y[1]['personel_id']]);
    }

    #[Test]
    public function verilen_sira_sigmazsa_baska_sira_denenir(): void
    {
        // Personel 1, 10:00-10:30 dolu: once 2 sonra 1 sigar.
        $y = OnlineMusaitlik::yerlestir([$this->kalem([1], 30), $this->kalem([2], 30)], self::SAAT_10,
            $this->takvim(['dolu' => [1 => [[600, 630]]]]));

        $this->assertSame([1, 0], array_column($y, 'index'));
    }

    #[Test]
    public function sure_boyunca_ortusen_randevu_baslangici_kapatir(): void
    {
        // Eski saatgetir yalniz baslangic izgarasina bakiyordu; 60 dk hizmet 10:30'daki randevuya carpar.
        $this->assertNull(OnlineMusaitlik::yerlestir([$this->kalem([1], 60)], self::SAAT_10, $this->takvim(['dolu' => [1 => [[630, 660]]]])));
    }

    #[Test]
    public function kapanisi_asan_hizmet_sigmaz(): void
    {
        $this->assertNull(OnlineMusaitlik::yerlestir([$this->kalem([1], 60)], 1050, $this->takvim()));
    }

    #[Test]
    public function personel_o_gun_kapaliysa_sigmaz_kayit_yoksa_salon_penceresi_gecerli(): void
    {
        $this->assertNull(OnlineMusaitlik::yerlestir([$this->kalem([1], 30)], self::SAAT_10, $this->takvim(['pencere' => [1 => null]])));
        $this->assertNotNull(OnlineMusaitlik::yerlestir([$this->kalem([2], 30)], self::SAAT_10, $this->takvim(['pencere' => [1 => null]])));
    }

    #[Test]
    public function salon_molasi_herkesi_kapatir(): void
    {
        $this->assertNull(OnlineMusaitlik::yerlestir([$this->kalem([1], 30)], self::SAAT_10, $this->takvim(['salon_mola' => [[600, 660]]])));
    }

    #[Test]
    public function farketmez_ilk_bos_adayi_atar(): void
    {
        $y = OnlineMusaitlik::yerlestir([$this->kalem([1, 2], 30)], self::SAAT_10, $this->takvim(['dolu' => [1 => [[600, 630]]]]));

        $this->assertSame(2, $y[0]['personel_id']);
    }

    #[Test]
    public function personelin_odasi_doluysa_personel_de_dolu_sayilir(): void
    {
        $t = $this->takvim(['personel_oda' => [1 => 7], 'oda_dolu' => [7 => [[600, 630]]]]);

        $this->assertNull(OnlineMusaitlik::yerlestir([$this->kalem([1], 30)], self::SAAT_10, $t));
    }

    #[Test]
    public function hizmet_odalarindan_bos_olan_secilir_hepsi_doluysa_sigmaz(): void
    {
        $t = $this->takvim(['oda_dolu' => [7 => [[600, 630]]]]);

        $this->assertSame(8, OnlineMusaitlik::yerlestir([$this->kalem([1], 30, [7, 8])], self::SAAT_10, $t)[0]['oda_id']);
        $this->assertNull(OnlineMusaitlik::yerlestir([$this->kalem([1], 30, [7])], self::SAAT_10, $t));
    }

    #[Test]
    public function baslangiclar_aralik_adimiyla_uretilir(): void
    {
        $this->assertSame([540, 570], OnlineMusaitlik::baslangiclar(540, 600, 30));
        $this->assertSame([], OnlineMusaitlik::baslangiclar(600, 600, 30));
    }

    #[Test]
    public function online_pencere_yalniz_baslangica_bakar(): void
    {
        $this->assertTrue(OnlineSaatKurali::izinliMi(null, 600));
        $this->assertFalse(OnlineSaatKurali::izinliMi([], 600));
        $this->assertTrue(OnlineSaatKurali::izinliMi([[600, 630]], 615));
        $this->assertFalse(OnlineSaatKurali::izinliMi([[600, 630]], 630));
    }

    #[Test]
    public function seyreltme_esit_aralikla_secer(): void
    {
        $this->assertSame([1, 3, 5], OnlineSaatKurali::seyrelt([1, 2, 3, 4, 5], 3));
        $this->assertSame([3], OnlineSaatKurali::seyrelt([1, 2, 3, 4, 5], 1));
        $this->assertSame([1, 2], OnlineSaatKurali::seyrelt([1, 2], 0));
    }
}
