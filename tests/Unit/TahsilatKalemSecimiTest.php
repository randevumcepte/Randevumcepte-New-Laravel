<?php

namespace Tests\Unit;

use App\Domain\Randevu\TahsilatKalemSecimi;
use PHPUnit\Framework\TestCase;

/** Randevudan tahsilata kalem elemesi — eski randevutahsilet/tahsilataGitRandevudan kurallari. */
class TahsilatKalemSecimiTest extends TestCase
{
    private function kalem(int $hizmet, array $ek = []): object
    {
        return (object) ($ek + ['hizmet_id' => $hizmet, 'fiyat' => 100]);
    }

    private function hizmetler(array $sonuc): array
    {
        return array_map(fn ($k) => $k->hizmet_id, $sonuc);
    }

    public function test_normal_kalem_eklenir_ucretsiz_de_normal_modda_eklenir(): void
    {
        $s = TahsilatKalemSecimi::eklenecekler([$this->kalem(1), $this->kalem(2, ['fiyat' => 0])], []);
        $this->assertSame([1, 2], $this->hizmetler($s));
    }

    public function test_yardimci_telafi_ve_hizmetsiz_satir_atlanir(): void
    {
        $s = TahsilatKalemSecimi::eklenecekler([
            $this->kalem(1, ['yardimci_personel' => 1]),
            $this->kalem(2, ['telafi_kaynak_rh_id' => 9]),
            $this->kalem(0),
            $this->kalem(3),
        ], []);
        $this->assertSame([3], $this->hizmetler($s));
    }

    public function test_paketten_dusen_ve_zaten_eklenen_hizmet_atlanir(): void
    {
        $s = TahsilatKalemSecimi::eklenecekler([$this->kalem(1), $this->kalem(2)], [1]);
        $this->assertSame([2], $this->hizmetler($s));
    }

    public function test_ekstra_modunda_ucretsiz_ve_adisyonda_olan_atlanir(): void
    {
        $s = TahsilatKalemSecimi::eklenecekler(
            [$this->kalem(1), $this->kalem(2, ['fiyat' => 0]), $this->kalem(3)],
            [],
            true,
            [1],
        );
        $this->assertSame([3], $this->hizmetler($s));
    }
}
