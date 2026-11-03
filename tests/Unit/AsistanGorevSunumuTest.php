<?php

namespace Tests\Unit;

use App\Domain\Asistan\AsistanAyari;
use App\Domain\Asistan\GorevSunumu;
use App\Domain\Asistan\GorevTuru;
use PHPUnit\Framework\TestCase;

class AsistanGorevSunumuTest extends TestCase
{
    private function randevu(array $ek = []): array
    {
        return array_merge([
            'id' => 7, 'tarih' => '2026-11-04', 'saat' => '14:30:00', 'arama_saat' => null,
            'hatirlatma_aramasi_yapildi' => null, 'hatirlatma_ulasilamadi' => null, 'randevuya_gelecek' => null,
            'asistan_guncelledi' => null, 'tekrar_arandi' => null, 'tekrar_arama_tarih_saat' => null,
            'hatirlatma_gorevi_iptal' => null, 'musteri_ad' => 'Ayşe Yılmaz',
        ], $ek);
    }

    public function test_hedef_tarih_tur_gun_farki(): void
    {
        $this->assertSame('2026-11-04', GorevTuru::Randevu->hedefTarih('2026-11-03'));
        $this->assertSame('2026-11-05', GorevTuru::Alacak->hedefTarih('2026-11-03'));
        $this->assertSame('2026-11-03', GorevTuru::Kampanya->hedefTarih('2026-11-03'));
        $this->assertSame('2026-12-01', GorevTuru::Randevu->hedefTarih('2026-11-30'));
    }

    public function test_bekleyen_randevu_karti_ve_mobil_uyumlu_alanlar(): void
    {
        $k = GorevSunumu::sun(GorevTuru::Randevu, $this->randevu(), false);

        $this->assertSame('randevu', $k['tur']);
        $this->assertSame(7, $k['randevu_id']);
        $this->assertSame('Ayşe Yılmaz isimli müşterinin 04.11.2026 randevu hatırlatmasını yapacağım.', $k['mesaj']);
        $this->assertSame('Hatırlatma araması yapılacak.', $k['sonuc']);
        $this->assertSame('bekliyor', $k['durum_kod']);
        $this->assertSame('', $k['durum']);
        $this->assertSame('14:30', $k['saat']);
        $this->assertTrue($k['iptal_edilebilir']);
        $this->assertFalse($k['iptal_edildi']);
    }

    public function test_danisan_salonunda_danisan_der(): void
    {
        $k = GorevSunumu::sun(GorevTuru::Randevu, $this->randevu(), true);
        $this->assertStringContainsString('isimli danışanın', $k['mesaj']);
    }

    public function test_ulasilamayan_randevu_tekrar_saati_ve_iptal_edilebilir(): void
    {
        $k = GorevSunumu::sun(GorevTuru::Randevu, $this->randevu([
            'hatirlatma_aramasi_yapildi' => 1, 'hatirlatma_ulasilamadi' => 1,
            'arama_saat' => '10:05:00', 'tekrar_arama_tarih_saat' => '2026-11-03 12:05:00',
        ]), false);

        $this->assertSame('Ulaşılamadı. 12:05 de tekrar arayacağım.', $k['sonuc']);
        $this->assertSame('ulasilamadi', $k['durum_kod']);
        $this->assertSame('Ulaşılamadı', $k['durum']);
        $this->assertSame('10:05', $k['saat']);
        $this->assertTrue($k['iptal_edilebilir']);
    }

    public function test_ulasilan_veya_tekrar_aranan_gorev_iptal_edilemez(): void
    {
        $ulasildi = $this->randevu(['hatirlatma_aramasi_yapildi' => 1, 'hatirlatma_ulasilamadi' => 0, 'randevuya_gelecek' => 1]);
        $this->assertFalse(GorevSunumu::iptalEdilebilir(GorevTuru::Randevu, $ulasildi));
        $this->assertSame('Randevuya gelecek.', GorevSunumu::sun(GorevTuru::Randevu, $ulasildi, false)['sonuc']);

        $tekrar = $this->randevu(['hatirlatma_aramasi_yapildi' => 1, 'hatirlatma_ulasilamadi' => 1, 'tekrar_arandi' => 1]);
        $this->assertFalse(GorevSunumu::iptalEdilebilir(GorevTuru::Randevu, $tekrar));
    }

    public function test_iptal_edilmis_gorev(): void
    {
        $k = GorevSunumu::sun(GorevTuru::Randevu, $this->randevu(['hatirlatma_gorevi_iptal' => 1]), false);
        $this->assertTrue($k['iptal_edildi']);
        $this->assertFalse($k['iptal_edilebilir']);
        $this->assertSame('iptal', $k['durum_kod']);
        $this->assertSame('İptal Edildi', $k['durum_etiket']);
    }

    public function test_alacak_durumu_eski_web_tersligi_duzeltildi(): void
    {
        $k = GorevSunumu::sun(GorevTuru::Alacak, [
            'id' => 3, 'tutar' => '1250.5', 'planlanan_odeme_tarihi' => '2026-11-05', 'taksitli_tahsilat_id' => 9,
            'senet_id' => null, 'arama_saat' => '11:00:00', 'hatirlatma_aramasi_yapildi' => 1,
            'hatirlatma_ulasilamadi' => 1, 'odenecek' => null, 'asistan_guncelledi' => null,
            'tekrar_arandi' => 0, 'tekrar_arama_tarih_saat' => null, 'hatirlatma_gorevi_iptal' => null,
            'musteri_ad' => 'Can',
        ], false);

        $this->assertSame('Ulaşılamadı', $k['durum']);
        $this->assertSame('Can isimli müşterinin 05.11.2026 tarihindeki 1.250,50 ₺ tutarındaki taksidinin ödeme hatırlatmasını yaptım.', $k['mesaj']);
        $this->assertSame('Ulaşılamadı. Tekrar arayacağım.', $k['sonuc']);
        $this->assertSame(3, $k['alacak_id']);
    }

    public function test_bagimsiz_alacak_alacaginin_der(): void
    {
        $k = GorevSunumu::sun(GorevTuru::Alacak, ['id' => 1, 'tutar' => 100, 'planlanan_odeme_tarihi' => '2026-11-05', 'musteri_ad' => 'X'], false);
        $this->assertStringContainsString('tutarındaki alacağının ödeme', $k['mesaj']);
    }

    public function test_kampanya_tam_cumle_ve_iptal_kurali(): void
    {
        $s = ['id' => 5, 'paket_isim' => 'Kış Paketi', 'arama_ile_gonderim' => 1, 'sms_ile_gonderim' => 0,
            'arama_yapildi' => 0, 'sms_gonderildi' => 0, 'asistan_tarih_saat' => '2026-11-03 16:00:00', 'tanitim_gorev_iptal' => null];
        $k = GorevSunumu::sun(GorevTuru::Kampanya, $s, false);

        $this->assertSame('Kış Paketi kampanya arama ile tanıtımını yapacağım.', $k['mesaj']);
        $this->assertSame('16:00', $k['saat']);
        $this->assertTrue($k['iptal_edilebilir']);
        $this->assertFalse(GorevSunumu::iptalEdilebilir(GorevTuru::Kampanya, ['arama_yapildi' => 1] + $s));
    }

    public function test_musteri_bilgi_karti_iptal_edilemez(): void
    {
        $k = GorevSunumu::sun(GorevTuru::Musteri, ['id' => 2, 'created_at' => '2026-11-03 09:15:00', 'musteri_ad' => 'Deniz'], false);
        $this->assertSame('Deniz isimli müşteriyi ekledim.', $k['mesaj']);
        $this->assertFalse($k['iptal_edilebilir']);
        $this->assertSame(['randevu', 'alacak', 'kampanya'], GorevTuru::iptalEdilebilenler());
    }

    public function test_ayar_tanimlari_benzersiz_ve_stok_haric(): void
    {
        $idler = array_map(fn ($a) => $a->value, AsistanAyari::cases());
        $this->assertNotContains(5, $idler);
        $this->assertSame([1, 4, 7, 2, 8, 3, 6], $idler);
        $this->assertSame(AsistanAyari::TekrarArama, AsistanAyari::anahtardan('tekrar_arama'));
        $this->assertNull(AsistanAyari::anahtardan('stok'));
    }
}
