<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use App\Models\Personeller;
use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use Illuminate\Support\Facades\Log;

/**
 * Eski ApiController::randevuKayitBildirimGonder ($hemen=true): WA-first + SMS yedegi, sonra musteri/personel push'u.
 * Her iki adimda hata yutulur, kayit yeniden denenmez; iptal akisindan farkli olarak istisnada SMS yedegi yoktur.
 */
class RandevuKayitGonderimi
{
    public function __construct(
        private RandevuVerisi $veri,
        private RandevuMesajKanali $kanal,
    ) {}

    public function gonder(array $mesajlar, int|string $salonId, int|string|null $randevuId, mixed $kaynak, bool $guncelleme, bool $zamanDegisti): void
    {
        if (! empty($mesajlar)) {
            try {
                $this->kanal->whatsappOncelikli($salonId, $mesajlar, $randevuId, $guncelleme ? 'guncelleme_bildirim' : 'yeni_randevu_bildirim');
            } catch (\Throwable $e) {
                Log::warning('randevuekleguncelle SMS afterResponse hata: '.$e->getMessage());
            }
        }

        try {
            $randevu = $randevuId ? Randevular::find($randevuId) : null;
            if ($randevu) {
                $this->pushlar($randevu, $salonId, $kaynak, $guncelleme, $zamanDegisti);
            }
        } catch (\Throwable $e) {
            Log::warning('randevuekleguncelle push afterResponse hata: '.$e->getMessage());
        }
    }

    private function pushlar(Randevular $randevu, int|string $salonId, mixed $kaynak, bool $guncelleme, bool $zamanDegisti): void
    {
        $salonAdi = $this->veri->salon($salonId)?->salon_adi ?? 'Salon';
        $tarihSaat = date('d.m.Y H:i', strtotime($randevu->tarih.' '.$randevu->saat));

        if ($guncelleme) {
            $this->guncellemePushlari($randevu, $salonId, $salonAdi, $tarihSaat, $zamanDegisti);
        } elseif (in_array($kaynak, ['uygulama', 'web'])) {
            $this->musteriTalebiPushlari($randevu, $salonId, $salonAdi, $tarihSaat);
        } else {
            RandevuPushu::yolla($this->musteriye($randevu, $salonId), BildirimTipleri::APPOINTMENT_CREATED,
                'Sizin için randevu oluşturuldu', $salonAdi.' • '.$tarihSaat, $randevu->id, 'musteri olusturuldu push fail');
        }
    }

    /** Guncellemede musteriye her durumda, personele yalniz zaman degistiyse. */
    private function guncellemePushlari(Randevular $randevu, int|string $salonId, string $salonAdi, string $tarihSaat, bool $zamanDegisti): void
    {
        RandevuPushu::yolla($this->musteriye($randevu, $salonId),
            $zamanDegisti ? BildirimTipleri::APPOINTMENT_TIME_CHANGED : BildirimTipleri::APPOINTMENT_CREATED,
            $zamanDegisti ? 'Randevunuzun zamanı değişti' : 'Randevunuz güncellendi',
            $zamanDegisti ? $salonAdi.' • Yeni: '.$tarihSaat : $salonAdi.' • '.$tarihSaat,
            $randevu->id, 'musteri guncelleme push fail');

        if (! $zamanDegisti) {
            return;
        }
        foreach ($randevu->hizmetler as $hizmet) {
            if (! $hizmet->personel_id) {
                continue;
            }
            RandevuPushu::yolla(PushBildirimi::personele((int) $hizmet->personel_id, (int) $salonId), BildirimTipleri::APPOINTMENT_TIME_CHANGED,
                'Randevu zamanı değişti', ($randevu->users->name ?? 'Müşteri').' • Yeni: '.$tarihSaat, $randevu->id, 'personel zaman degisti push fail');
        }
    }

    /** Musteri kendi olusturdu: musteriye "talep alindi", atanan personel + salon yoneticilerine (role<5) "yeni talep". */
    private function musteriTalebiPushlari(Randevular $randevu, int|string $salonId, string $salonAdi, string $tarihSaat): void
    {
        RandevuPushu::yolla($this->musteriye($randevu, $salonId), BildirimTipleri::APPOINTMENT_CREATED,
            'Randevu Talebiniz Alındı', $salonAdi.' • '.$tarihSaat.' • Onay bekleniyor', $randevu->id, 'musteri talep push fail');

        $personelIdler = [];
        foreach ($randevu->hizmetler as $hizmet) {
            if ($hizmet->personel_id) {
                $personelIdler[] = (int) $hizmet->personel_id;
            }
        }
        foreach (Personeller::where('salon_id', $salonId)->where('role_id', '<', 5)->pluck('id')->toArray() as $yoneticiId) {
            $personelIdler[] = (int) $yoneticiId;
        }

        $musteriAdi = $randevu->users->name ?? 'Müşteri';
        foreach (array_values(array_unique($personelIdler)) as $personelId) {
            RandevuPushu::yolla(PushBildirimi::personele($personelId, (int) $salonId), BildirimTipleri::APPOINTMENT_CREATED,
                'Yeni randevu talebi', $musteriAdi.' • '.$tarihSaat, $randevu->id, 'personel talep push fail');
        }
    }

    private function musteriye(Randevular $randevu, int|string $salonId): PushBildirimi
    {
        return PushBildirimi::musteriye((int) $randevu->user_id, (int) $salonId);
    }
}
