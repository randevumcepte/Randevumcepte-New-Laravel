<?php

namespace Tests\Unit\Domain\Whatsapp;

use App\Domain\Whatsapp\BaglantiDurumu;
use App\Domain\Whatsapp\GonderimDurumu;
use App\Domain\Whatsapp\GonderimOzeti;
use App\Domain\Whatsapp\IsletmeLinkleri;
use App\Domain\Whatsapp\KontorDurumu;
use App\Domain\Whatsapp\KontorPaketleri;
use App\Domain\Whatsapp\KontorTalepMesaji;
use App\Domain\Whatsapp\WhatsappDurumu;
use PHPUnit\Framework\TestCase;

class WhatsappDomainTest extends TestCase
{
    public function test_kopru_cevabi_kanonik_koda_cevrilir(): void
    {
        $this->assertSame('connected', BaglantiDurumu::koprudan(['connected' => true, 'status' => 'open']));
        $this->assertSame('disconnected', BaglantiDurumu::koprudan(['connected' => false, 'status' => 'no-session']));
        $this->assertSame('disconnected', BaglantiDurumu::koprudan([]));
        $this->assertSame('disconnected', BaglantiDurumu::koprudan(null));
        $this->assertSame('qr-pending', BaglantiDurumu::koprudan(['status' => 'qr-pending']));
    }

    public function test_bilinmeyen_baglanti_durumu_oldugu_gibi_gosterilir(): void
    {
        $this->assertSame('Bağlı', BaglantiDurumu::tanim('connected')['ad']);
        $this->assertSame(['ad' => 'yeni-durum', 'renk' => 'gri'], BaglantiDurumu::tanim('yeni-durum'));
    }

    public function test_gonderim_sozlugu_tum_durumlari_kapsar(): void
    {
        $this->assertSame([0, 1, 2, 3], array_column(GonderimDurumu::sozluk(), 'kod'));
    }

    public function test_ozet_gun_hafta_ay_ve_oran_ayrimi(): void
    {
        $satir = fn ($t, $d, $a) => (object) ['tarih' => $t, 'durum' => (string) $d, 'adet' => (string) $a];
        $o = GonderimOzeti::hesapla([
            $satir('2026-11-04', 1, 8), $satir('2026-11-04', 2, 1), $satir('2026-11-04', 3, 1), $satir('2026-11-04', 0, 2),
            $satir('2026-10-29', 1, 4),   // 7 günün ilk günü
            $satir('2026-10-28', 1, 5),   // yalnız 30 gün
            $satir('2026-10-06', 2, 3),   // 30 günün ilk günü
            $satir('2026-10-05', 1, 99),  // aralık dışı: sayılmaz
        ], '2026-11-04');

        $this->assertSame(['toplam' => 12, 'basari' => 8, 'fail' => 1, 'fallback' => 1], $o['bugun']);
        $this->assertSame(['toplam' => 16, 'basari' => 12], $o['hafta']);
        $this->assertSame(['toplam' => 24, 'basari' => 17], $o['ay']);
        $this->assertSame(75.0, $o['basariOrani']);
        $this->assertCount(30, $o['gunler']);
        $this->assertSame('2026-10-06', $o['gunler'][0]['gun']);
        $this->assertSame(['gun' => '2026-11-04', 'basari' => 8, 'fail' => 1, 'fallback' => 1], $o['gunler'][29]);
    }

    public function test_ozet_bos_veride_sifir_oran(): void
    {
        $this->assertSame(0, GonderimOzeti::hesapla([], '2026-11-04')['basariOrani']);
    }

    public function test_kontor_paketleri_eski_ekranla_ayni_rakamlar(): void
    {
        $l = array_column(KontorPaketleri::liste(), null, 'adet');
        $this->assertSame([10000, 20000, 40000, 60000, 80000, 100000], array_keys($l));
        $this->assertSame([0, 400, 1600, 3300, 5800, 8500], array_column($l, 'avantaj'));
        $this->assertSame([0.285, 0.265, 0.245, 0.23, 0.213, 0.2], array_column($l, 'birim_fiyat'));
        $this->assertSame('13.800 TL', $l[60000]['fiyat_metin']);
        $this->assertSame(30, $l[100000]['indirim_yuzde']);
        $this->assertTrue($l[100000]['en_avantajli']);
        $this->assertFalse($l[80000]['en_avantajli']);
    }

    public function test_kontor_paketi_kodla_bulunur(): void
    {
        $this->assertSame(5300, KontorPaketleri::bul('kontor_20000')['fiyat']);
        $this->assertNull(KontorPaketleri::bul('kontor_1'));
    }

    private function kontor(int $bakiye, bool $kontorlu, ?string $bitis = null): array
    {
        return KontorDurumu::olustur(new WhatsappDurumu(true, '905', $bakiye, $kontorlu), $bitis);
    }

    public function test_ucretsiz_donem_bandi_ve_uyari_yok(): void
    {
        $k = $this->kontor(0, false, '2026-11-30');
        $this->assertSame('ucretsiz', $k['seviye']);
        $this->assertStringContainsString('30 Kasım 2026', $k['baslik']);
        $this->assertStringContainsString('01 Aralık 2026', $k['metin']);
        $this->assertSame('ŞU AN ÜCRETSİZ', $k['rozet']);
        $this->assertNull($k['not']);
        $this->assertNull($k['uyari']);

        $b = $this->kontor(2500, false, '2026-11-30');
        $this->assertSame('2.500 KONTÖR', $b['rozet']);
        $this->assertStringContainsString('2.500', $b['not']);
    }

    public function test_ucretsiz_donem_bitis_yoksa_global_tarih(): void
    {
        $this->assertStringContainsString('31 Ağustos 2026', $this->kontor(0, false)['baslik']);
        $this->assertStringContainsString('31 Ağustos 2026', $this->kontor(0, false, '0000-00-00')['baslik']);
    }

    public function test_kontorlu_donem_seviyeleri_ve_uyari_sikligi(): void
    {
        $this->assertSame(['bitti', 99], [$this->kontor(0, true)['seviye'], $this->kontor(0, true)['uyari']['gunluk_limit']]);
        $this->assertSame(['kritik', 3], [$this->kontor(500, true)['seviye'], $this->kontor(500, true)['uyari']['gunluk_limit']]);
        $this->assertSame(['dusuk', 1], [$this->kontor(1000, true)['seviye'], $this->kontor(1000, true)['uyari']['gunluk_limit']]);
        $this->assertSame('normal', $this->kontor(1001, true)['seviye']);
        $this->assertNull($this->kontor(1001, true)['uyari']);
    }

    public function test_talep_mesaji_bos_alanlari_atlar(): void
    {
        $paket = KontorPaketleri::bul('kontor_10000');
        $tam = KontorTalepMesaji::olustur((object) ['id' => 7, 'salon_adi' => 'Salon', 'yetkili_adi' => 'Ayşe', 'yetkili_telefon' => '0532', 'telefon_1' => '0212'], $paket, ' ara ', '04.11.2026 10:00');
        $this->assertSame("💰 KONTÖR TALEBİ\nSalon: Salon\nPaket: 10.000 Kontör (2.850 TL)\nYetkili: Ayşe\nTel: 0532\nİletişim/Not: ara\nID: 7\n04.11.2026 10:00", $tam);

        // yetkili_* kolonları olmayan satır hata vermez; telefon yedeğe düşer
        $az = KontorTalepMesaji::olustur((object) ['id' => 7, 'salon_adi' => 'Salon', 'telefon_1' => '0212'], $paket, null, 'z');
        $this->assertSame("💰 KONTÖR TALEBİ\nSalon: Salon\nPaket: 10.000 Kontör (2.850 TL)\nTel: 0212\nID: 7\nz", $az);
    }

    public function test_linkler_varsayilan_baslik_ve_kismi_guncelleme(): void
    {
        $s = IsletmeLinkleri::sun((object) ['konum_linki' => 'https://k', 'instagram_baslik' => '  ', 'web_baslik' => 'Site']);
        $this->assertSame('Instagram', $s['instagram_baslik']);
        $this->assertSame('Site', $s['web_baslik']);
        $this->assertSame('', $s['web_linki']);

        $this->assertSame(['konum_linki' => null, 'web_baslik' => 'X'], IsletmeLinkleri::guncelleme(['konum_linki' => ' ', 'web_baslik' => ' X ', 'salon_id' => 1]));
    }
}
