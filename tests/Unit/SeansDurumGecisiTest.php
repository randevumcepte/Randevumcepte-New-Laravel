<?php

namespace Tests\Unit;

use App\Domain\Randevu\SeansDurumGecisi;
use PHPUnit\Framework\TestCase;

/**
 * SeansDurumGecisi saf kurallari — ozellikle TELAFI (geldi=2) korumasini kilitler.
 */
class SeansDurumGecisiTest extends TestCase
{
    private function seanslar(): array
    {
        return [
            ['id' => 10, 'geldi' => null],
            ['id' => 11, 'geldi' => 0],
            ['id' => 12, 'geldi' => 2],   // telafi slotu
            ['id' => 13, 'geldi' => '2'], // DB string donerse de telafi
        ];
    }

    public function test_geldi_secimsiz_hepsi_geldi_telafi_dahil(): void
    {
        $p = SeansDurumGecisi::geldi($this->seanslar(), null);
        $this->assertSame([10, 11, 12, 13], array_keys($p['guncelle']));
        $this->assertSame(['geldi' => 1], $p['guncelle'][12]);
        $this->assertSame([], $p['sil']);
    }

    public function test_geldi_secimli_secilmeyen_telafi_disi_silinir_telafi_korunur(): void
    {
        $p = SeansDurumGecisi::geldi($this->seanslar(), [10], ['10' => 3]);
        $this->assertSame([10 => ['geldi' => 1, 'dusulen_miktar' => 3]], $p['guncelle']);
        $this->assertSame([11], $p['sil']);
    }

    public function test_geldi_miktar_min_bir_varsayilan_bir(): void
    {
        $p = SeansDurumGecisi::geldi($this->seanslar(), [10, 11], [10 => 0]);
        $this->assertSame(1, $p['guncelle'][10]['dusulen_miktar']);
        $this->assertSame(1, $p['guncelle'][11]['dusulen_miktar']);
    }

    public function test_geldi_randevuya_ait_olmayan_secim_yok_sayilir(): void
    {
        $p = SeansDurumGecisi::geldi($this->seanslar(), [999]);
        $this->assertSame([], $p['guncelle']);
        $this->assertSame([10, 11], $p['sil']);
    }

    public function test_gelmedi_telafiye_dokunmaz(): void
    {
        $p = SeansDurumGecisi::gelmedi($this->seanslar());
        $this->assertSame([10 => ['geldi' => 0], 11 => ['geldi' => 0]], $p['guncelle']);
    }

    public function test_isaret_kaldir_telafiye_dokunmaz(): void
    {
        $p = SeansDurumGecisi::isaretKaldir($this->seanslar());
        $this->assertSame([10, 11], array_keys($p['guncelle']));
        $this->assertNull($p['guncelle'][10]['geldi']);
    }

    public function test_iptal_telafi_disini_siler_telafiyi_korur(): void
    {
        $p = SeansDurumGecisi::iptal($this->seanslar());
        $this->assertSame([10, 11], $p['sil']);
        $this->assertSame([], $p['guncelle']);
    }
}
