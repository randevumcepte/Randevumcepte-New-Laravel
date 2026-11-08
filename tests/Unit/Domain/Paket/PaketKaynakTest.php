<?php

namespace Tests\Unit\Domain\Paket;

use App\Services\Paket\PaketKullanimi;
use PHPUnit\Framework\TestCase;

/** Ders plani / seans kaynagi kurali — web SeansHavuzu ve mobil kaynaklarOrtalama ayni metodu kullanir. */
class PaketKaynakTest extends TestCase
{
    public function test_suresi_dolan_paket_kaynak_olamaz(): void
    {
        $this->assertFalse(PaketKullanimi::kaynakOlabilir(['sinirsiz' => true, 'suresi_doldu' => true], 0));
        $this->assertFalse(PaketKullanimi::kaynakOlabilir(['sinirsiz' => false, 'suresi_doldu' => true], 5));
    }

    public function test_sinirsiz_kalana_bakmaz_seansli_kalan_ister(): void
    {
        $this->assertTrue(PaketKullanimi::kaynakOlabilir(['sinirsiz' => true, 'suresi_doldu' => false], 0));
        $this->assertTrue(PaketKullanimi::kaynakOlabilir(['sinirsiz' => false, 'suresi_doldu' => false], 2));
        $this->assertFalse(PaketKullanimi::kaynakOlabilir(['sinirsiz' => false, 'suresi_doldu' => false], 0));
    }

    public function test_durum_yoksa_eski_davranis_kalan(): void
    {
        $this->assertTrue(PaketKullanimi::kaynakOlabilir(null, 1));
        $this->assertFalse(PaketKullanimi::kaynakOlabilir(null, 0));
    }
}
