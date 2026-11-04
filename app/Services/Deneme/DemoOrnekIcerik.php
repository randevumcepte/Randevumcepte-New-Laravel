<?php

namespace App\Services\Deneme;

use App\Domain\Stok\HareketTipi;
use App\Services\Stok\StokHareketServisi;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;

/**
 * Yeni deneme salonunu dolu gosteren ornek kayitlar (eski demoHesapKur icerigi). Musteri ve hizmet
 * id'leri eskiyle ayni ortak kayitlardir. Eski koddan bilincli farklar:
 *   - salonsuz, randevusuz yetim randevu + randevu_hizmetler satirlari YAZILMAZ (hicbir ekranda gorunmuyordu)
 *   - bos request->sube yuzunden NULL kalan olusturan alanlari personelle dolar
 *   - bos on gorusme saatinden turetilen bozuk saatler yerine simdiki saat kullanilir
 */
class DemoOrnekIcerik
{
    private const MUSTERI_1 = 1;

    private const MUSTERI_2 = 2;

    private const ON_GORUSME_HIZMETI = 1;

    private const HIZMET_1 = 2;

    private const HIZMET_2 = 3;

    private const FORM_SAYISI = 7;

    private const ACILIS_STOGU = 5;

    public function __construct(private StokHareketServisi $stok) {}

    public function olustur(int $salonId, int $yetkiliId, int $personelId): void
    {
        DB::transaction(function () use ($salonId, $yetkiliId, $personelId) {
            $urun1 = $this->urun($salonId, 'Örnek Ürün 1', 100);
            $urun2 = $this->urun($salonId, 'Örnek Ürün 2', 200);
            $paket1 = $this->paket($salonId, 'Örnek Paket 1', self::HIZMET_1, 100);
            $paket2 = $this->paket($salonId, 'Örnek Paket 2', self::HIZMET_2, 200);

            $ortak = ['salon_id' => $salonId, 'hatirlatma_tarihi' => date('Y-m-d'), 'personel_id' => $personelId, 'email' => 'ornek@email.com'];
            $this->onGorusme($ortak + ['user_id' => self::MUSTERI_1, 'ad_soyad' => 'Örnek Müşteri/Danışan 1', 'cinsiyet' => 0, 'adres' => 'Örnek Adres 1',
                'aciklama' => 'Örnek Açıklama 1', 'il_id' => 1, 'musteri_tipi' => 1, 'meslek' => 'Örnek Meslek 1', 'urun_id' => $urun1], $yetkiliId);
            $this->onGorusme($ortak + ['user_id' => self::MUSTERI_2, 'ad_soyad' => 'Örnek Müşteri/Danışan 2', 'cinsiyet' => 1, 'adres' => 'Örnek Adres 2',
                'aciklama' => 'Örnek Açıklama 2', 'il_id' => 2, 'musteri_tipi' => 2, 'meslek' => 'Örnek Meslek 2', 'paket_id' => $paket1], $yetkiliId);

            $this->satislar($salonId, $yetkiliId, $personelId, $urun2, $paket2);
            $this->etkinlikKampanya($salonId);
            $this->ajandaFormlar($salonId, $personelId);
        });
    }

    private function urun(int $salonId, string $ad, int $fiyat): int
    {
        $depoId = (int) $this->stok->varsayilanDepo($salonId)->id;
        $id = ZamanliKayit::ekle('urunler', ['urun_adi' => $ad, 'fiyat' => $fiyat, 'dusuk_stok_siniri' => 1, 'salon_id' => $salonId, 'varsayilan_depo_id' => $depoId, 'aktif' => 1]);
        $this->stok->kaydet(['salon_id' => $salonId, 'urun_id' => $id, 'depo_id' => $depoId, 'miktar' => self::ACILIS_STOGU, 'tip' => HareketTipi::Acilis, 'aciklama' => 'Demo urun acilis stogu']);

        return $id;
    }

    private function paket(int $salonId, string $ad, int $hizmetId, int $fiyat): int
    {
        $id = ZamanliKayit::ekle('paketler', ['paket_adi' => $ad, 'aktif' => 1, 'salon_id' => $salonId]);
        ZamanliKayit::ekle('paket_hizmetler', ['paket_id' => $id, 'hizmet_id' => $hizmetId, 'seans' => 1, 'fiyat' => $fiyat]);

        return $id;
    }

    private function onGorusme(array $satir, int $yetkiliId): void
    {
        $id = ZamanliKayit::ekle('on_gorusmeler', $satir);
        $randevuId = $this->randevu($satir['salon_id'], $satir['user_id'], $yetkiliId, ['on_gorusme_id' => $id, 'sms_hatirlatma' => 1]);
        $this->randevuHizmeti($randevuId, self::ON_GORUSME_HIZMETI, $satir['personel_id']);
    }

    private function satislar(int $salonId, int $yetkiliId, int $personelId, int $urunId, int $paketId): void
    {
        $bugun = date('Y-m-d');
        $urunAdisyon = $this->adisyon($salonId, $yetkiliId);
        ZamanliKayit::ekle('adisyon_urunler', ['islem_tarihi' => $bugun, 'adisyon_id' => $urunAdisyon, 'urun_id' => $urunId, 'adet' => 1, 'fiyat' => 200, 'personel_id' => $personelId]);

        $adisyonPaket = ZamanliKayit::ekle('adisyon_paketler', ['adisyon_id' => $this->adisyon($salonId, $yetkiliId), 'paket_id' => $paketId, 'fiyat' => 200,
            'baslangic_tarihi' => $bugun, 'seans_araligi' => 1, 'personel_id' => $personelId]);
        $randevuId = $this->randevu($salonId, self::MUSTERI_1, $personelId);
        ZamanliKayit::ekle('adisyon_paket_seanslar', ['adisyon_paket_id' => $adisyonPaket, 'seans_tarih' => $bugun, 'hizmet_id' => self::HIZMET_2, 'seans_no' => 1, 'seans_saat' => date('H:i:s'), 'randevu_id' => $randevuId]);
        $this->randevuHizmeti($randevuId, self::HIZMET_2, $personelId, ['sure_dk' => 60]);
    }

    private function etkinlikKampanya(int $salonId): void
    {
        $etkinlik = ZamanliKayit::ekle('etkinlikler', ['etkinlik_adi' => 'Örnek Etkinlik 1', 'tarih_saat' => date('Y-m-d H:i:s'), 'fiyat' => 1000, 'salon_id' => $salonId, 'aktifmi' => 1, 'mesaj' => 'Etkinliğimizde davetlisiniz örnek mesaj 1']);
        $kampanya = ZamanliKayit::ekle('kampanya_yonetimi', ['paket_isim' => 'Örnek Paket 1', 'hizmet_adi' => 'Örnek Hizmet Kampanyası', 'fiyat' => 200, 'seans' => 1, 'salon_id' => $salonId, 'aktifmi' => 1, 'mesaj' => 'Örnek Hizmet Kampanyası']);
        foreach ([self::MUSTERI_1, self::MUSTERI_2] as $musteri) {
            ZamanliKayit::ekle('etkinlik_katilimcilari', ['etkinlik_id' => $etkinlik, 'user_id' => $musteri]);
            ZamanliKayit::ekle('kampanya_katilimcilari', ['kampanya_id' => $kampanya, 'user_id' => $musteri]);
        }
    }

    private function ajandaFormlar(int $salonId, int $personelId): void
    {
        ZamanliKayit::ekle('ajanda', ['ajanda_baslik' => 'Örnek Hatırlatma', 'ajanda_tarih' => date('Y-m-d'), 'ajanda_hatirlatma' => 0, 'ajanda_saat' => date('H:i:s'),
            'ajanda_icerik' => 'Örnek ajanda notu', 'ajanda_hatirlatma_saat' => date('H:i:s'), 'salon_id' => $salonId, 'ajanda_olusturan' => $personelId, 'aktif' => 1]);
        foreach ([self::MUSTERI_1, self::MUSTERI_2] as $musteri) {
            ZamanliKayit::ekle('arsiv', ['user_id' => $musteri, 'form_id' => random_int(1, self::FORM_SAYISI), 'personel_id' => $personelId, 'cevapladi' => 0, 'cevapladi2' => 0, 'salon_id' => $salonId, 'form_olusturan' => $personelId]);
        }
    }

    private function adisyon(int $salonId, int $yetkiliId): int
    {
        return ZamanliKayit::ekle('adisyonlar', ['user_id' => self::MUSTERI_1, 'salon_id' => $salonId, 'olusturan_id' => $yetkiliId, 'tarih' => date('Y-m-d')]);
    }

    private function randevu(int $salonId, int $userId, int $olusturanId, array $ek = []): int
    {
        return ZamanliKayit::ekle('randevular', $ek + ['user_id' => $userId, 'salon_id' => $salonId, 'tarih' => date('Y-m-d'), 'saat' => date('H:i:s'), 'salon' => 1, 'durum' => 1, 'olusturan_personel_id' => $olusturanId]);
    }

    private function randevuHizmeti(int $randevuId, int $hizmetId, int $personelId, array $ek = []): void
    {
        ZamanliKayit::ekle('randevu_hizmetler', $ek + ['randevu_id' => $randevuId, 'hizmet_id' => $hizmetId, 'personel_id' => $personelId, 'saat' => date('H:i:s'), 'saat_bitis' => date('H:i:s', strtotime('+1 hour'))]);
    }
}
