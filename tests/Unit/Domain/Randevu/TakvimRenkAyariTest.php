<?php

namespace Tests\Unit\Domain\Randevu;

use App\Domain\Randevu\TakvimRenkAyari;
use PHPUnit\Framework\TestCase;

class TakvimRenkAyariTest extends TestCase
{
    public function test_tek_renk_acikken_renk_sirasi_yok_sayilir(): void
    {
        $a = TakvimRenkAyari::salondan((object) [
            'takvim_tek_renk_aktif' => 1,
            'takvim_tek_renk' => '#aabbcc',
            'takvim_renk_sirasi' => '["#112233"]',
        ]);

        $this->assertSame('0xFFAABBCC', $a->tekRenk);
        $this->assertSame([], $a->sira);
    }

    public function test_renk_sirasi_gecersiz_ogeleri_atar_iki_bicimi_okur(): void
    {
        $a = TakvimRenkAyari::salondan((object) [
            'takvim_renk_sirasi' => json_encode(['#112233', ['bg' => '#445566', 'text' => '#ffffff'], ['bg' => 'kirmizi'], 5]),
        ]);

        $this->assertSame([
            ['bg' => '0xFF112233', 'text' => null],
            ['bg' => '0xFF445566', 'text' => '0xFFFFFFFF'],
        ], $a->sira);
    }

    public function test_yazi_rengi_yalniz_aktif_ve_gecerliyse_doner(): void
    {
        $this->assertNull(TakvimRenkAyari::salondan((object) ['takvim_yazi_rengi' => '#000000'])->yaziRengi);
        $this->assertNull(TakvimRenkAyari::salondan((object) ['takvim_yazi_rengi_aktif' => 1, 'takvim_yazi_rengi' => 'siyah'])->yaziRengi);
        $this->assertSame('0xFF000000', TakvimRenkAyari::salondan((object) ['takvim_yazi_rengi_aktif' => 1, 'takvim_yazi_rengi' => '#000000'])->yaziRengi);
        $this->assertNull(TakvimRenkAyari::salondan(null)->tekRenk);
    }
}
