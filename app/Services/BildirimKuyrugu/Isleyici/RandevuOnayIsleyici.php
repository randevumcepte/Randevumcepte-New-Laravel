<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Domain\Bildirim\DogalTarih;
use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\BildirimKuyrugu\Kanal\UygulamaIciBildirim;
use App\Services\BildirimKuyrugu\Randevu\RandevuMesajKanali;
use App\Services\BildirimKuyrugu\Randevu\RandevuPushu;
use App\Services\BildirimKuyrugu\Randevu\RandevuVerisi;
use Illuminate\Support\Facades\Log;

/**
 * randevu_onay: eski ApiController::randevuOnayBildirimleri ($hemen=true). yetkili_id = onaylayan. SMS ayari ayar_id=2.
 * Eskiden gelen ve KORUNAN durum: onayda WA denenmez, yalniz SMS gider. Personel metni eskiden tarihten bagimsiz "yarın" derdi;
 * artik randevu gununu soyler (DogalTarih).
 * Musteriye uygulama ici bildirim satiri ayrica yazilmaz, yalniz push'un satiri olusur.
 */
class RandevuOnayIsleyici extends RandevuTabanliIsleyici
{
    private const AYAR_ID = 2;

    public function __construct(
        private RandevuVerisi $veri,
        private RandevuMesajKanali $kanal,
        private UygulamaIciBildirim $bildirim,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $salonId = $randevu->salon_id;
        $isletme = $this->veri->salon($salonId);
        $mesajlar = [];

        $musteriOnayMesaji = $isletme->salon_adi.' için oluşturduğunuz '.date('d.m.Y', strtotime($randevu->tarih)).' '.date('H:i', strtotime($randevu->saat)).' tarihli randevu talebiniz onaylanmıştır. Randevunuza 15 dk önce gelmenizi rica ederiz. Detaylı bilgi için bize ulaşın. 0'.$isletme->telefon_1;

        if ($this->veri->ayarAcikMi($salonId, self::AYAR_ID, 'musteri')) {
            $mesajlar[] = ['to' => $randevu->users->cep_telefon, 'message' => $musteriOnayMesaji];
        }

        foreach ($randevu->hizmetler as $hizmet) {
            $mesaj = $randevu->users->name.' isimli müşterinin '.DogalTarih::ifade(date('Y-m-d', strtotime($randevu->tarih)).' '.$hizmet->saat).' '.$this->veri->hizmetAdi($hizmet).' randevusu '.$this->veri->yetkiliAlani($kayit->yetkili_id, 'name').' tarafından onaylanmıştır.';

            if ($this->veri->ayarAcikMi($salonId, self::AYAR_ID, 'personel')) {
                $mesajlar[] = ['to' => $this->veri->personelGsm($hizmet->personel_id), 'message' => $mesaj];
            }

            $this->bildirim->ekle($salonId, $mesaj, '#', $hizmet->personel_id, null, $this->veri->yetkiliAlani($kayit->yetkili_id, 'profil_resim'), $randevu->id);

            if ($hizmet->personel_id) {
                RandevuPushu::yolla(PushBildirimi::personele((int) $hizmet->personel_id, (int) $salonId), BildirimTipleri::APPOINTMENT_APPROVED,
                    'Randevu Onaylandı', $mesaj, $randevu->id, 'api personel onay push');
            }
        }

        if (count($mesajlar) > 0) {
            try {
                $this->kanal->sms($mesajlar, $salonId);
            } catch (\Throwable $e) {
                Log::warning('randevuonayla SMS (shutdown) hata: '.$e->getMessage());
            }
        }

        // Musteri push: SMS metniyle birebir.
        if ($randevu->user_id) {
            RandevuPushu::yolla(PushBildirimi::musteriye((int) $randevu->user_id, (int) $salonId), BildirimTipleri::APPOINTMENT_APPROVED,
                'Randevunuz Onaylandı', $musteriOnayMesaji, $randevu->id, 'randevuonayla push hata');
        }
    }
}
