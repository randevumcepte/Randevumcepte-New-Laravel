<?php

namespace Tests\Unit;

use App\Domain\Asistan\MobilGorevGorunumu as G;
use App\Domain\Asistan\MobilGorevSunumu;
use PHPUnit\Framework\TestCase;

/** Mobil gorev metinleri eski easistandata + easistandatadashboard SQL'iyle birebir. */
class AsistanMobilGorevSunumuTest extends TestCase
{
    private function randevu(array $ek = []): array
    {
        return $ek + [
            'id' => 7, 'tarih' => '2026-11-04', 'saat' => '14:30:00', 'arama_saat' => null, 'hatirlatma_aramasi_yapildi' => null,
            'hatirlatma_ulasilamadi' => null, 'randevuya_gelecek' => null, 'asistan_guncelledi' => null, 'tekrar_arandi' => null,
            'tekrar_arama_tarih_saat' => null, 'hatirlatma_gorevi_iptal' => null, 'musteri_ad' => 'Ayşe',
        ];
    }

    public function test_randevu_liste_ve_ozet_metinleri_farkli(): void
    {
        $l = MobilGorevSunumu::randevu($this->randevu(), false, G::Liste);
        $this->assertSame('Ayşe isimli müşterinin 04.11.2026 randevu hatırlatmasını  yapacağım.', $l['mesaj']);
        $this->assertSame('Randevu hatırlatma araması yapılacak.', $l['sonuc']);
        $this->assertSame('Ayşe', $l['baslik']);
        $this->assertSame('14:30', $l['saat']);
        $this->assertSame(['randevu', 1, 0, 7], [$l['tur'], $l['iptal_edilebilir'], $l['iptal_edildi'], $l['randevu_id']]);

        $o = MobilGorevSunumu::randevu($this->randevu(), true, G::Ozet);
        $this->assertStringContainsString('danışanın', $o['mesaj']);
        $this->assertSame('Hatırlatma araması yapılacak.', $o['sonuc']);
        $this->assertSame('Randevu Hatırlatması', $o['baslik']);
        $this->assertArrayNotHasKey('tur', $o);
    }

    public function test_randevu_durum_listede_eski_yazim(): void
    {
        $s = $this->randevu(['hatirlatma_aramasi_yapildi' => 1, 'hatirlatma_ulasilamadi' => 0, 'arama_saat' => '10:15:00']);

        $this->assertSame('Ulaşıldı<', MobilGorevSunumu::randevu($s, false, G::Liste)['durum']);
        $this->assertSame('Ulaşıldı', MobilGorevSunumu::randevu($s, false, G::Ozet)['durum']);
        $this->assertSame('10:15', MobilGorevSunumu::randevu($s, false, G::Liste)['saat']);
    }

    public function test_tekrar_saati_yoksa_metin_null_saat_varsa_eski_cumle(): void
    {
        $s = $this->randevu(['hatirlatma_aramasi_yapildi' => 1, 'hatirlatma_ulasilamadi' => 1]);
        $this->assertNull(MobilGorevSunumu::randevu($s, false, G::Liste)['sonuc']);

        $s['tekrar_arama_tarih_saat'] = '2026-11-04 16:00:00';
        $this->assertSame('Randevu hatırlatması için , 16:00 de tekrar arayacağım', MobilGorevSunumu::randevu($s, false, G::Liste)['sonuc']);
        $this->assertSame('Ulaşılamadı, 16:00 de tekrar arayacağım', MobilGorevSunumu::randevu($s, false, G::Ozet)['sonuc']);
    }

    public function test_alacak_ham_tutar_ve_taksit_senet_ayrimi(): void
    {
        $s = [
            'id' => 3, 'tutar' => '1500.00', 'planlanan_odeme_tarihi' => '2026-11-05', 'taksitli_tahsilat_id' => 9, 'arama_saat' => null,
            'hatirlatma_aramasi_yapildi' => null, 'hatirlatma_ulasilamadi' => null, 'odenecek' => null, 'asistan_guncelledi' => null,
            'tekrar_arandi' => null, 'tekrar_arama_tarih_saat' => null, 'hatirlatma_gorevi_iptal' => null, 'musteri_ad' => 'Ali',
        ];

        $l = MobilGorevSunumu::alacak($s, false, G::Liste);
        $this->assertSame('Ali isimli müşterinin 05.11.2026 tarihindeki 1500.00 ₺ tutarındaki taksitinin ödeme hatırlatmasını yapacağım.', $l['mesaj']);
        $this->assertSame('Alacak hatırlatma araması yapılacak.', $l['sonuc']);
        $this->assertSame(3, $l['randevu_id']);

        $s['taksitli_tahsilat_id'] = null;
        $this->assertStringContainsString('senedinin', MobilGorevSunumu::alacak($s, false, G::Ozet)['mesaj']);
        $this->assertSame('Alacak Hatırlatması', MobilGorevSunumu::alacak($s, false, G::Ozet)['baslik']);
    }

    public function test_kampanya_kanali_ve_bos_alanlar(): void
    {
        $s = ['id' => 4, 'paket_isim' => 'Yaz', 'arama_ile_gonderim' => 1, 'sms_ile_gonderim' => 0, 'arama_yapildi' => 0, 'sms_gonderildi' => 0, 'asistan_tarih_saat' => '2026-11-04 11:00:00', 'tanitim_gorev_iptal' => null];

        $k = MobilGorevSunumu::kampanya($s, G::Liste);
        $this->assertSame('Yaz kampanya arama  ile tanıtımını  yapacağım.', $k['mesaj']);
        $this->assertNull($k['sonuc']);
        $this->assertSame(['Kampanya Tanıtımı', '11:00', 1], [$k['baslik'], $k['saat'], $k['iptal_edilebilir']]);

        $s['sms_ile_gonderim'] = null;
        $this->assertStringContainsString(' arama ve SMS ', MobilGorevSunumu::kampanya($s, G::Ozet)['mesaj']);
    }

    public function test_musteri_satiri(): void
    {
        $m = MobilGorevSunumu::musteri(['id' => 2, 'created_at' => '2026-11-04 09:05:00', 'musteri_ad' => 'Ece']);

        $this->assertSame('Ece isimli müşteriyi ekledim.', $m['mesaj']);
        $this->assertSame('Müşteri işletmeye eklendi', $m['durum']);
        $this->assertSame(['09:05', 'musteri', 0], [$m['saat'], $m['tur'], $m['iptal_edilebilir']]);
    }
}
