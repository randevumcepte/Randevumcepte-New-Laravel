<?php

namespace Tests\Unit\Domain\Deneme;

use App\Domain\Deneme\DemoSablonu;
use PHPUnit\Framework\TestCase;

class DemoSablonuTest extends TestCase
{
    public function test_sms_ayarlari_eski_matrisle_ayni(): void
    {
        $ayarlar = array_column(DemoSablonu::smsAyarlari(), null, 'ayar_id');

        $this->assertCount(23, $ayarlar);
        $this->assertSame(['ayar_id' => 1, 'musteri' => 1, 'personel' => 1], $ayarlar[1]);
        $this->assertSame(['ayar_id' => 4, 'musteri' => 1, 'personel' => 0], $ayarlar[4]);
        $this->assertSame(['ayar_id' => 17, 'musteri' => 0, 'personel' => 0], $ayarlar[17]);
        $this->assertSame(['ayar_id' => 23, 'musteri' => 0, 'personel' => 0], $ayarlar[23]);
    }

    public function test_pazar_saatleri_sifir_diger_gunler_mesai(): void
    {
        $saatler = DemoSablonu::calismaSaatleri();

        $this->assertCount(7, $saatler);
        $this->assertSame('07:00:00', $saatler[0]['baslangic_saati']);
        $this->assertSame('20:00:00', $saatler[5]['bitis_saati']);
        $this->assertSame('00:00:00', $saatler[6]['bitis_saati']);
    }

    public function test_mola_yok(): void
    {
        $this->assertSame([0], array_values(array_unique(array_column(DemoSablonu::molaSaatleri(), 'mola_var'))));
    }
}
