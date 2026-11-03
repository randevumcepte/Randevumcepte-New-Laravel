<?php

namespace Tests\Unit;

use App\Domain\Santral\OzelKarsilamaMenusu;
use PHPUnit\Framework\TestCase;

class OzelKarsilamaMenusuTest extends TestCase
{
    public function test_normalize_gecersizleri_eler_tekil_tus_sirali(): void
    {
        $sonuc = OzelKarsilamaMenusu::normalize([
            ['tus' => '3', 'etiket' => '  Muhasebe   birimi ', 'tip' => 'dahili', 'hedef' => '1-0-2'],
            ['tus' => '1', 'etiket' => '<b>Randevu</b>', 'tip' => 'xyz', 'hedef' => '10013'],
            ['tus' => '1', 'etiket' => 'Tekrar', 'tip' => 'kuyruk', 'hedef' => '10014'],  // ayni tus → elenir
            ['tus' => '0', 'etiket' => 'Sifir', 'tip' => 'kuyruk', 'hedef' => '10015'],   // gecersiz tus
            ['tus' => '4', 'etiket' => '', 'tip' => 'kuyruk', 'hedef' => '10016'],        // bos etiket
            ['tus' => '5', 'etiket' => 'Kisa', 'tip' => 'kuyruk', 'hedef' => '7'],        // hedef < 2 hane
        ]);

        $this->assertSame([
            ['tus' => '1', 'etiket' => 'Randevu', 'tip' => 'kuyruk', 'hedef' => '10013'],
            ['tus' => '3', 'etiket' => 'Muhasebe birimi', 'tip' => 'dahili', 'hedef' => '102'],
        ], $sonuc);
    }

    public function test_metin(): void
    {
        $this->assertSame('', OzelKarsilamaMenusu::metin([]));
        $metin = OzelKarsilamaMenusu::metin([['tus' => '2', 'etiket' => 'Randevu', 'tip' => 'kuyruk', 'hedef' => '10013']]);
        $this->assertStringStartsWith('Randevu için ikiyi tuşlayınız.', $metin);
    }
}
