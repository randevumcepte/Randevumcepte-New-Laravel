<?php

namespace Tests\Unit\Domain\Oturum;

use App\Domain\Oturum\TokenTipi;
use PHPUnit\Framework\TestCase;

class TokenTipiTest extends TestCase
{
    public function test_tipli_token_yalniz_kendi_turunde_gecer(): void
    {
        $this->assertTrue(TokenTipi::kabul(TokenTipi::MUSTERI, TokenTipi::MUSTERI, false, null, 'v2-web'));
        $this->assertFalse(TokenTipi::kabul(TokenTipi::MUSTERI, TokenTipi::ISLETME, true, null, 'v2-web'));
        $this->assertFalse(TokenTipi::kabul(TokenTipi::ISLETME, TokenTipi::MUSTERI, true, null, 'v2-web'));
    }

    public function test_tipsiz_token_gecis_ayarina_bagli(): void
    {
        $this->assertTrue(TokenTipi::kabul(null, TokenTipi::MUSTERI, true, 'appToken', 'v2-web'));
        $this->assertFalse(TokenTipi::kabul(null, TokenTipi::MUSTERI, false, 'appToken', 'v2-web'));
        $this->assertFalse(TokenTipi::kabul(null, TokenTipi::ISLETME, false, 'appToken', 'v2-web'));
    }

    public function test_kopru_token_i_gecis_kapaliyken_yalniz_isletmede_gecer(): void
    {
        $this->assertTrue(TokenTipi::kabul(null, TokenTipi::ISLETME, false, 'v2-web', 'v2-web'));
        $this->assertFalse(TokenTipi::kabul(null, TokenTipi::MUSTERI, false, 'v2-web', 'v2-web'));
    }

    public function test_kapsamdan_tip_cozulur(): void
    {
        $this->assertSame(TokenTipi::ISLETME, TokenTipi::coz(['isletme']));
        $this->assertNull(TokenTipi::coz([]));
        $this->assertNull(TokenTipi::coz(['baska']));
    }
}
