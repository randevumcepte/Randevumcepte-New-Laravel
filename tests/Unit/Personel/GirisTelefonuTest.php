<?php

namespace Tests\Unit\Personel;

use App\Domain\Personel\GirisTelefonu as G;
use PHPUnit\Framework\TestCase;

/** Giris telefonu (gsm1): numara tek hesaba ait, baska isletmenin hesabi baglanamaz / telefonu degistirilemez. */
class GirisTelefonuTest extends TestCase
{
    public function test_numara_baska_hesapta_ise_engel(): void
    {
        $this->assertSame(G::BASKA_HESAPTA, G::numaraEngeli(5, 9));
        $this->assertSame(G::BASKA_HESAPTA, G::numaraEngeli(null, 9), 'yeni hesap da alinmis numarayi alamaz');
        $this->assertNull(G::numaraEngeli(5, 5), 'kendi numarasi');
        $this->assertNull(G::numaraEngeli(5, null), 'serbest numara');
    }

    public function test_baglama_yalniz_bu_salonda_ya_da_salonsuz_hesaba(): void
    {
        $this->assertNull(G::baglamaEngeli([], 20), 'salonsuz hesap');
        $this->assertNull(G::baglamaEngeli([20, 20], 20), 'ayni salonun eski personeli');
        $this->assertSame(G::BASKA_ISLETMEDE, G::baglamaEngeli([30], 20));
        $this->assertSame(G::BASKA_ISLETMEDE, G::baglamaEngeli([20, '30'], 20));
    }

    public function test_baska_salonlarda_kayitli_hesabin_telefonu_yalniz_kendisi(): void
    {
        $this->assertNull(G::degisimEngeli([20], 20, false));
        $this->assertNull(G::degisimEngeli([], 20, false));
        $this->assertSame(G::DEGISIM_YALNIZ_KENDISI, G::degisimEngeli([20, 30], 20, false));
        $this->assertNull(G::degisimEngeli([20, 30], 20, true), 'kendisi');
    }
}
