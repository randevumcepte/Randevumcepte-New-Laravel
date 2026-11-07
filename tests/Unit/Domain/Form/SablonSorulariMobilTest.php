<?php

namespace Tests\Unit\Domain\Form;

use App\Domain\Form\SablonSorulari;
use PHPUnit\Framework\TestCase;

/** Mobil şablon kaydı: boş metin ve `zorunlu` ekrandaki gibi saklanır, tip yine denetlenir. DB YOK. */
class SablonSorulariMobilTest extends TestCase
{
    public function test_web_varsayilani_bos_metni_atar_zorunlu_tipten_gelir(): void
    {
        $sonuc = SablonSorulari::normalize([
            ['tip' => 'metin', 'soru' => 'Ad', 'zorunlu' => false],
            ['tip' => 'metin_blogu', 'soru' => '  '],
        ]);

        $this->assertSame([['tip' => 'metin', 'soru' => 'Ad', 'zorunlu' => true]], $sonuc);
    }

    public function test_mobil_bos_metni_ve_zorunlu_degerini_korur(): void
    {
        $sonuc = SablonSorulari::normalize('[{"tip":"metin_blogu","soru":"","zorunlu":false},{"tip":"onay_kutusu","soru":"Onay","zorunlu":false}]', true);

        $this->assertSame([
            ['tip' => 'metin_blogu', 'soru' => '', 'zorunlu' => false],
            ['tip' => 'onay_kutusu', 'soru' => 'Onay', 'zorunlu' => false],
        ], $sonuc);
    }

    public function test_mobil_gecersiz_tipi_reddeder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SablonSorulari::normalize([['tip' => 'yok', 'soru' => 'x']], true);
    }
}
