<?php

namespace Tests\Unit;

use App\Domain\Randevu\Cakisma;
use App\Domain\Randevu\HizmetZinciri;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HizmetZinciriCakismaTest extends TestCase
{
    public function test_ardisik_zincir(): void
    {
        $s = HizmetZinciri::satirlar('10:00', [['sure_dk' => 30], ['sure_dk' => 45]]);
        $this->assertSame(['10:00', '10:30'], [$s[0]['saat'], $s[0]['saat_bitis']]);
        $this->assertSame(['10:30', '11:15'], [$s[1]['saat'], $s[1]['saat_bitis']]);
    }

    public function test_birlestir_paralel_baslar(): void
    {
        $s = HizmetZinciri::satirlar('10:00', [['sure_dk' => 30, 'birlestir' => '1'], ['sure_dk' => 60], ['sure_dk' => 15]]);
        $this->assertSame('10:00', $s[1]['saat']);   // ilk birlestir → ikinci ayni anda
        $this->assertSame('11:00', $s[2]['saat']);   // ikinci ardisik → ucuncu 11:00
    }

    public function test_toplam_bitis_paralel_dahil(): void
    {
        $this->assertSame('11:30', HizmetZinciri::toplamBitis('10:00', [['sure_dk' => 30, 'birlestir' => '1'], ['sure_dk' => 60]]));
    }

    public function test_sure_zorunlu(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HizmetZinciri::satirlar('10:00', [['sure_dk' => '']]);
    }

    public function test_ortusur_kenar_temasi_cakisma_degil(): void
    {
        $this->assertFalse(Cakisma::ortusur('11:00', '12:00', '10:00', '11:00'));
        $this->assertTrue(Cakisma::ortusur('10:30', '11:30', '10:00', '11:00'));
        $this->assertTrue(Cakisma::ortusur('09:00', '12:00', '10:00', '11:00'));
    }

    public function test_aralikta_sinir_dahil_ve_gece_yarisi(): void
    {
        $this->assertTrue(Cakisma::araliktaMi('09:00', '18:00', '18:00'));
        $this->assertFalse(Cakisma::araliktaMi('09:00', '18:00', '18:01'));
        $this->assertTrue(Cakisma::araliktaMi('22:00', '02:00', '01:00'));
    }
}
