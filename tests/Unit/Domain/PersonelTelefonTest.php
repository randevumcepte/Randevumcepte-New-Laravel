<?php

namespace Tests\Unit\Domain;

use App\Domain\Personel\Telefon;
use Tests\TestCase;

/**
 * Telefon saf-mantik dogrulamasi (DB'siz). Normalize + maske tespiti eski
 * ApiController@telefon_no_format_duzenle / telefonMaskeliMi ile birebir olmali —
 * duplicate-telefon kontrolu bu kanonik bicime dayanir.
 */
class PersonelTelefonTest extends TestCase
{
    public function test_normalize_bastaki_ulke_ve_sifir_atar(): void
    {
        $this->assertSame('5321234567', Telefon::normalize('05321234567'));
        $this->assertSame('5321234567', Telefon::normalize('+905321234567'));
        $this->assertSame('5321234567', Telefon::normalize('905321234567'));
        $this->assertSame('5321234567', Telefon::normalize('0532 (123) 45-67'));
        $this->assertSame('', Telefon::normalize(null));
        $this->assertSame('', Telefon::normalize(''));
    }

    public function test_maskeli_veya_eksik_haneli_tespit(): void
    {
        $this->assertTrue(Telefon::maskeliMi('5068 *** ** 33')); // yildizli
        $this->assertTrue(Telefon::maskeliMi('12345'));          // <10 hane
        $this->assertFalse(Telefon::maskeliMi('5321234567'));    // tam
        $this->assertFalse(Telefon::maskeliMi(null));
        $this->assertFalse(Telefon::maskeliMi(''));
    }
}
