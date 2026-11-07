<?php

namespace Tests\Unit\Domain;

use App\Services\Personel\PersonelOkumaServisi;
use PHPUnit\Framework\TestCase;

/** Personel DTO: giris hesabi bayragi ve yetkiye bagli alanlar. */
class PersonelDtoTest extends TestCase
{
    private function satir(?int $hesapId): object
    {
        return (object) [
            'id' => 1, 'salon_id' => 20, 'hesap_turu' => 5, 'personel_adi' => 'Ali', 'profil_resmi' => null,
            'cinsiyet' => 1, 'unvan' => null, 'cep_telefon' => '5321', 'renk' => 2, 'dahili_no' => null,
            'takvim_sirasi' => 1, 'takvimde_gorunsun' => 1, 'aktif' => 1, 'maas' => '1000.00',
            'hizmet_prim_yuzde' => 10, 'urun_prim_yuzde' => 10, 'paket_prim_yuzde' => 10, 'hesap_id' => $hesapId,
        ];
    }

    public function test_hesabi_var_giris_hesabindan_cikar(): void
    {
        $okuma = new PersonelOkumaServisi();

        $this->assertTrue($okuma->donustur($this->satir(7))['hesabi_var']);
        $this->assertFalse($okuma->donustur($this->satir(null))['hesabi_var']);
    }

    public function test_hesap_kimligi_cevapta_yok(): void
    {
        $this->assertArrayNotHasKey('hesap_id', (new PersonelOkumaServisi())->donustur($this->satir(7)));
    }

    public function test_yetkisizde_maas_ve_telefon_null(): void
    {
        $dto = (new PersonelOkumaServisi())->donustur($this->satir(7), ['maas' => false, 'telefon' => false]);

        $this->assertNull($dto['maas']);
        $this->assertNull($dto['hizmet_prim_yuzde']);
        $this->assertNull($dto['cep_telefon']);
    }
}
