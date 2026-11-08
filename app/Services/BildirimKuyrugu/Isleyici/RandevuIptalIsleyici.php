<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\BildirimKuyrugu\Kanal\UygulamaIciBildirim;
use App\Services\BildirimKuyrugu\Randevu\RandevuMesajKanali;
use App\Services\BildirimKuyrugu\Randevu\RandevuPushu;
use App\Services\BildirimKuyrugu\Randevu\RandevuVerisi;
use Illuminate\Support\Facades\Log;

/**
 * randevu_iptal: eski ApiController::randevuIptalBildirimleri ($hemen=true). payload.red. Musteri + personel mesaji WA-first,
 * SMS ayari ayar_id=3; uygulama ici bildirim ve personel push'u ayardan bagimsiz. Musteri kendi iptal ettiyse (durum=3) musteriye push gitmez.
 */
class RandevuIptalIsleyici extends RandevuTabanliIsleyici
{
    private const AYAR_ID = 3;

    public function __construct(
        private RandevuVerisi $veri,
        private RandevuMesajKanali $kanal,
        private UygulamaIciBildirim $bildirim,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $red = ! empty($payload['red']);
        $salonId = $randevu->salon_id;
        $isletme = $this->veri->salon($salonId);
        $mesajlar = [];

        $musteriMesaji = $red
            ? $isletme->salon_adi.' için oluşturduğunuz '.date('d.m.Y', strtotime($randevu->tarih)).' '.date('H:i', strtotime($randevu->saat)).' randevu talebiniz reddedilmiştir. Detaylı bilgi için bize ulaşın. 0'.$isletme->telefon_1
            : $isletme->salon_adi.' için oluşturulan '.date('d.m.Y', strtotime($randevu->tarih)).' '.date('H:i', strtotime($randevu->saat)).' randevunuz iptal edilmiştir. Detaylı bilgi için bize ulaşın. 0'.$isletme->telefon_1;

        if ($this->veri->ayarAcikMi($salonId, self::AYAR_ID, 'musteri')) {
            $mesajlar[] = ['to' => $randevu->users->cep_telefon, 'message' => $musteriMesaji];
        }
        $resim = $this->veri->yetkiliAlani($randevu->olusturan_personel_id, 'profil_resim');
        $this->bildirim->ekle($salonId, $musteriMesaji, '#', null, $randevu->user_id, $resim, $randevu->id);

        foreach ($randevu->hizmetler as $hizmet) {
            $mesaj = $red ? $this->redPersonelMesaji($randevu, $hizmet) : $this->iptalPersonelMesaji($randevu, $hizmet);

            if ($this->veri->ayarAcikMi($salonId, self::AYAR_ID, 'personel')) {
                $mesajlar[] = ['to' => $this->veri->personelGsm($hizmet->personel_id), 'message' => $mesaj];
            }

            $this->bildirim->ekle($salonId, $mesaj, '#', $hizmet->personel_id, null, $resim, $randevu->id);

            if ($hizmet->personel_id) {
                $alici = PushBildirimi::personele((int) $hizmet->personel_id, (int) $salonId);
                $red
                    ? RandevuPushu::yolla($alici, BildirimTipleri::APPOINTMENT_CANCELLED, 'Randevu Reddedildi', $mesaj, $randevu->id, 'api personel red push', ['islem' => 'red'])
                    : RandevuPushu::yolla($alici, BildirimTipleri::APPOINTMENT_CANCELLED, $randevu->durum == 3 ? 'Müşteri Randevuyu İptal Etti' : 'Randevu İptal Edildi', $mesaj, $randevu->id, 'api personel iptal push', ['iptal_eden' => $randevu->durum == 3 ? 'musteri' : 'salon']);
            }
        }

        if (count($mesajlar) > 0) {
            $this->mesajlariGonder($mesajlar, $salonId, $randevu->id);
        }

        // Musteri kendi iptal ettiyse push mantiksiz; metin SMS metniyle birebir.
        if ($randevu->durum != 3 && $musteriMesaji && $randevu->user_id) {
            RandevuPushu::yolla(PushBildirimi::musteriye((int) $randevu->user_id, (int) $salonId), BildirimTipleri::APPOINTMENT_CANCELLED,
                $red ? 'Randevu Talebiniz Reddedildi' : 'Randevunuz İptal Edildi', $musteriMesaji, $randevu->id, 'musteri iptal/red push hata',
                ['iptal_eden' => 'salon', 'red' => $red ? '1' : '0']);
        }
    }

    private function redPersonelMesaji(Randevular $randevu, RandevuHizmetler $hizmet): string
    {
        return $randevu->users->name.' için '.date('d.m.Y', strtotime($randevu->tarih)).' '.date('H:i', strtotime($hizmet->saat)).' '.$this->veri->hizmetAdi($hizmet).' randevusu '.$this->veri->yetkiliAlani($randevu->olusturan_personel_id, 'name').' tarafından reddedilmiştir.';
    }

    private function iptalPersonelMesaji(Randevular $randevu, RandevuHizmetler $hizmet): string
    {
        if ($randevu->durum == 3) {
            return $randevu->users->name.' '.date('d.m.Y', strtotime($randevu->tarih)).' '.date('H:i', strtotime($hizmet->saat)).' '.$this->veri->hizmetAdi($hizmet).' randevusunu iptal etmiştir.';
        }

        return $randevu->users->name.' isimli müşterinin '.date('d.m.Y', strtotime($randevu->tarih)).' '.date('H:i', strtotime($hizmet->saat)).' '.$this->veri->hizmetAdi($hizmet).' randevusu '.$this->veri->yetkiliAlani($randevu->olusturan_personel_id, 'name').' tarafından iptal edilmiştir.';
    }

    /** Eskideki gibi: WA-first istisna verirse listenin tamami SMS'e gider (WA'ya girmis mesajlar da); ikinci hata yutulur. */
    private function mesajlariGonder(array $mesajlar, int|string $salonId, int|string $randevuId): void
    {
        try {
            $this->kanal->whatsappOncelikli($salonId, $mesajlar, $randevuId, 'iptal_bildirim');
        } catch (\Throwable $e) {
            // Liste yeniden SMS'e verilmez: WhatsApp'tan ya da SMS'ten gitmis mesaj ikinci kez gider (eskideki mukerrer).
            Log::warning('randevuiptalet bildirim hatasi: '.$e->getMessage());
        }
    }
}
