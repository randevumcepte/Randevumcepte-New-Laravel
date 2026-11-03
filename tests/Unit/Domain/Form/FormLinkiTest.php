<?php

namespace Tests\Unit\Domain\Form;

use App\Domain\Form\FormLinki;
use App\Domain\Form\SabitFormlar;
use PHPUnit\Framework\TestCase;

/** Form link metni ve sabit form (9-13) kuralları — tek kaynak. DB YOK. */
class FormLinkiTest extends TestCase
{
    private const H = 'app.ornek.com';

    private function a(array $x = []): array
    {
        return $x + ['id' => 7, 'user_id' => 42, 'form_id' => 3, 'is_sozlesme' => 0, 'dogrulama_kodu' => '1234'];
    }

    public function test_klasik_form_linki(): void
    {
        $this->assertSame(' Formu doldurmak için : https://app.ornek.com/musteriformdoldurma/7/42 Onay Kodu:1234', FormLinki::musteriMesaji(self::H, $this->a(), false));
    }

    public function test_ozel_sayfali_formlar_yeniden_gonderimde_de_korunur(): void
    {
        $this->assertStringContainsString('/musteriformdoldurma2/7/42', FormLinki::musteriMesaji(self::H, $this->a(['form_id' => 9]), false));
        $this->assertStringContainsString('/musteriformdoldurma6/7/42', FormLinki::musteriMesaji(self::H, $this->a(['form_id' => 13]), false));
    }

    public function test_dinamik_ve_sozlesme_linki(): void
    {
        $this->assertStringContainsString('/onam-form/7/42 | Onay Kodu: 1234', FormLinki::musteriMesaji(self::H, $this->a(), true));
        $this->assertStringContainsString('/sozlesme/7/42 | Onay Kodu: 1234', FormLinki::musteriMesaji(self::H, $this->a(['form_id' => 0, 'is_sozlesme' => 1]), true));
    }

    public function test_personel_imza_linki_yalniz_klasik_formda(): void
    {
        $this->assertSame(' İmza atmak için : https://app.ornek.com/personelformdoldurma/7/5', FormLinki::personelMesaji(self::H, $this->a(), false, 5));
        $this->assertNull(FormLinki::personelMesaji(self::H, $this->a(), true, 5));
        $this->assertNull(FormLinki::personelMesaji(self::H, $this->a(['form_id' => 10]), false, 5));
        $this->assertNull(FormLinki::personelMesaji(self::H, $this->a(), false, 0));
    }

    public function test_dogrulama_kodu_4_hane(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}$/', FormLinki::dogrulamaKodu());
    }

    public function test_sabit_form_ek_alan_kurallari(): void
    {
        $this->assertTrue(SabitFormlar::ekAlanlar(9)['kapora_zorunlu']);
        $this->assertTrue(SabitFormlar::ekAlanlar(12)['hizmet']);
        $this->assertFalse(SabitFormlar::ekAlanlar(3)['ucret']);
        $this->assertNotNull(SabitFormlar::ucretHatasi(10, 100.0, null));
        $this->assertSame('Kapora miktarı toplam ücretten fazla olamaz!', SabitFormlar::ucretHatasi(9, 100.0, 150.0));
        $this->assertNull(SabitFormlar::ucretHatasi(9, 100.0, 50.0));
        $this->assertNull(SabitFormlar::ucretHatasi(3, null, null));
    }
}
