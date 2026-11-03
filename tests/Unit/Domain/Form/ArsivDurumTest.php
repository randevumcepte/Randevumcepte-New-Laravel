<?php

namespace Tests\Unit\Domain\Form;

use App\Domain\Form\ArsivDurum;
use PHPUnit\Framework\TestCase;

/** Arşiv satırı tür/durum/aksiyon kuralları (eski arsiv_liste_getir CASE'leri) — DB YOK. */
class ArsivDurumTest extends TestCase
{
    public function test_tur_ayrimi(): void
    {
        $this->assertSame('form', ArsivDurum::tur(['form_id' => 5]));
        $this->assertSame('harici', ArsivDurum::tur(['form_id' => 0, 'is_sozlesme' => 0]));
        $this->assertSame('sozlesme', ArsivDurum::tur(['form_id' => 0, 'is_sozlesme' => 1]));
    }

    public function test_durum_etiketleri(): void
    {
        $this->assertSame('onaylandi', ArsivDurum::durum(['durum' => 1])['kod']);
        $this->assertSame('iptal', ArsivDurum::durum(['durum' => 0])['kod']);
        $this->assertSame('onay_bekleniyor', ArsivDurum::durum(['durum' => null, 'cevapladi' => 1, 'cevapladi2' => 0])['kod']);
        // Sözleşme: salon imzaladı (cevapladi2=1) → müşteri onayı bekleniyor (eskiyle aynı).
        $this->assertSame('onay_bekleniyor', ArsivDurum::durum(['durum' => null, 'cevapladi' => 0, 'cevapladi2' => 1])['kod']);
        $this->assertSame('form_bekleniyor', ArsivDurum::durum(['durum' => null, 'cevapladi' => 0, 'cevapladi2' => 0])['kod']);
        $this->assertSame('harici', ArsivDurum::durum(['durum' => null, 'cevapladi' => null, 'cevapladi2' => null])['kod']);
        $this->assertSame('Onaylandı', ArsivDurum::durum(['durum' => '1'])['etiket']);
    }

    public function test_bekleyen_form_tum_aksiyonlar(): void
    {
        $a = ArsivDurum::aksiyonlar(['form_id' => 3, 'durum' => null], ['gonder' => true]);
        $this->assertTrue($a['gonder'] && $a['onayla'] && $a['iptal'] && $a['indir'] && $a['yazdir']);
    }

    public function test_onayli_form_yalniz_tekrar_gonder(): void
    {
        $a = ArsivDurum::aksiyonlar(['form_id' => 3, 'durum' => 1], ['gonder' => true]);
        $this->assertTrue($a['gonder']);
        $this->assertFalse($a['onayla']);
        $this->assertFalse($a['iptal']);
    }

    public function test_gonder_yetkisi_yoksa_gonder_yok(): void
    {
        $a = ArsivDurum::aksiyonlar(['form_id' => 3, 'durum' => null], ['gonder' => false]);
        $this->assertFalse($a['gonder']);
        $this->assertTrue($a['onayla']);
    }

    public function test_harici_belge_gonderilemez_iptal_edilebilir(): void
    {
        $a = ArsivDurum::aksiyonlar(['form_id' => 0, 'durum' => null], ['gonder' => true]);
        $this->assertFalse($a['gonder']);
        $this->assertFalse($a['onayla']);
        $this->assertTrue($a['iptal']);
        $this->assertFalse(ArsivDurum::aksiyonlar(['form_id' => 0, 'durum' => 0], ['gonder' => true])['iptal']);
    }

    public function test_gecersiz_sekme_tumu(): void
    {
        $this->assertSame('tumu', ArsivDurum::sekme('xyz'));
        $this->assertSame('harici', ArsivDurum::sekme('harici'));
    }
}
