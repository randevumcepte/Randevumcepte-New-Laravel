<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\IsletmeYetkilileri;
use App\Models\Personeller;
use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappMesajFormat;
use App\Services\BildirimKuyrugu\Randevu\RandevuMesajKanali;
use App\Services\BildirimKuyrugu\Randevu\RandevuPushu;
use App\Services\BildirimKuyrugu\Randevu\RandevuVerisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * randevu_tasindi: eski ApiController::randevuTasimaBildirimleri (surukle-birak / boyutlandirma). Musteri + personel push'u,
 * WA-first + SMS (ayar_id=12). Eski genel try/catch korunur: islem sirasindaki hata log'a yazilir, kayit yeniden denenmez. Zorunlu
 * payload alanlari (rh_id, yeni_saat, yeni_bitis, takvim_turu) eskideki gibi try disinda okunur: eksikse hata, yeniden deneme.
 */
class RandevuTasindiIsleyici extends RandevuTabanliIsleyici
{
    private const AYAR_ID = 12;

    public function __construct(
        private RandevuVerisi $veri,
        private RandevuMesajKanali $kanal,
        private WhatsappKanali $whatsapp,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $rhId = (int) $payload['rh_id'];
        $yeniSaat = $payload['yeni_saat'];
        $yeniBitis = $payload['yeni_bitis'];
        $takvimTuru = (int) $payload['takvim_turu'];
        $eskiSaat = $payload['eski_saat'] ?? null;
        $eskiBitis = $payload['eski_bitis'] ?? null;
        $eskiPersonelId = $payload['eski_personel_id'] ?? null;
        $resourceId = $payload['resource_id'] ?? null;

        try {
            $randevu = $randevu->fresh(['hizmetler', 'users']);
            $degisen = $randevu->hizmetler->firstWhere('id', $rhId);
            if (! $degisen) {
                return;
            }

            $saatDegisti = $eskiSaat === null || $eskiSaat !== $yeniSaat;
            $bitisDegisti = $eskiBitis === null || $eskiBitis !== $yeniBitis;
            $zamanlamaDegisti = $saatDegisti || $bitisDegisti; // saat veya sure degisti (resize dahil)
            $personelDegisti = ($takvimTuru == 1) && $eskiPersonelId && $eskiPersonelId != $resourceId;

            $salon = $this->veri->salon($randevu->salon_id);
            $musteriAdi = $randevu->users->name ?? 'Müşteri';
            $hizmetAdi = $this->veri->hizmetAdiVarsa($degisen) ?? 'Hizmet';
            // Mesajda hem baslangic hem bitis: 19.05.2026 10:00-11:30
            $tarihSaat = date('d.m.Y', strtotime($randevu->tarih)).' '.date('H:i', strtotime($yeniSaat)).'-'.date('H:i', strtotime($yeniBitis));

            if ($zamanlamaDegisti) {
                $this->zamanlamaPushlari($randevu, $degisen, $salon, $musteriAdi, $hizmetAdi, $tarihSaat, $saatDegisti);
            }
            if ($personelDegisti) {
                $this->personelPushlari($randevu, (int) $eskiPersonelId, (int) $resourceId, $musteriAdi, $hizmetAdi, $tarihSaat);
            }
            if ($zamanlamaDegisti || $personelDegisti) {
                $personelIdleri = [];
                if ($zamanlamaDegisti && $degisen->personel_id) {
                    $personelIdleri[] = (int) $degisen->personel_id;
                }
                if ($personelDegisti) {
                    $personelIdleri[] = (int) $eskiPersonelId;
                    if ($resourceId) {
                        $personelIdleri[] = (int) $resourceId;
                    }
                }
                $this->mesajlar($randevu, $salon, array_values(array_unique($personelIdleri)), $musteriAdi, $hizmetAdi, $tarihSaat);
            }
        } catch (\Throwable $e) {
            Log::warning('suruklebirak push/sms genel hata: '.$e->getMessage());
        }
    }

    private function zamanlamaPushlari(Randevular $randevu, RandevuHizmetler $degisen, ?object $salon, string $musteriAdi, string $hizmetAdi, string $tarihSaat, bool $saatDegisti): void
    {
        RandevuPushu::yolla(PushBildirimi::musteriye((int) $randevu->user_id, (int) $randevu->salon_id), BildirimTipleri::APPOINTMENT_TIME_CHANGED,
            $saatDegisti ? 'Randevunuzun zamanı değişti' : 'Randevunuzun süresi güncellendi',
            ($salon->salon_adi ?? 'Salon').' • '.$hizmetAdi.' • Yeni: '.$tarihSaat, $randevu->id, 'suruklebirak musteri push fail');

        if ($degisen->personel_id) {
            RandevuPushu::yolla(PushBildirimi::personele((int) $degisen->personel_id, (int) $randevu->salon_id), BildirimTipleri::APPOINTMENT_TIME_CHANGED,
                $saatDegisti ? 'Randevu zamanı değişti' : 'Randevu süresi güncellendi',
                $musteriAdi.' • '.$hizmetAdi.' • Yeni: '.$tarihSaat, $randevu->id, 'suruklebirak personel push fail');
        }
    }

    /** Eski personele "alindi", yeni personele "atandi". */
    private function personelPushlari(Randevular $randevu, int $eskiPersonelId, int $yeniPersonelId, string $musteriAdi, string $hizmetAdi, string $tarihSaat): void
    {
        $govde = $musteriAdi.' • '.$hizmetAdi.' • '.$tarihSaat;
        RandevuPushu::yolla(PushBildirimi::personele($eskiPersonelId, (int) $randevu->salon_id), BildirimTipleri::STAFF_ASSIGNED,
            'Randevu sizden alındı', $govde, $randevu->id, 'suruklebirak eski personel push fail');
        RandevuPushu::yolla(PushBildirimi::personele($yeniPersonelId, (int) $randevu->salon_id), BildirimTipleri::STAFF_ASSIGNED,
            'Size yeni randevu atandı', $govde, $randevu->id, 'suruklebirak yeni personel push fail');
    }

    private function mesajlar(Randevular $randevu, ?object $salon, array $personelIdleri, string $musteriAdi, string $hizmetAdi, string $tarihSaat): void
    {
        $ayar = DB::table('salon_sms_ayarlari')->where('salon_id', $randevu->salon_id)->where('ayar_id', self::AYAR_ID)->first();
        $waAcik = $this->kanal->whatsappAcikMi($salon);
        $smsListesi = [];

        if ($ayar && $ayar->musteri == 1 && $randevu->users && ! empty($randevu->users->cep_telefon)) {
            $telefon = $randevu->users->cep_telefon;
            $musteriMesaji = ($salon->salon_adi ?? 'Salon').' tarafından randevunuz '.$tarihSaat.' olarak güncellendi.';
            // WA metnine uygulama daveti eklenir; SMS sade kalir.
            $waMesaji = WhatsappMesajFormat::uygulamaDavetiEk($musteriMesaji, $salon, $randevu->user_id);
            if (! ($waAcik && $this->whatsappIleGitti($salon, $telefon, $waMesaji, $randevu, $randevu->user_id, 'suruklebirak musteri WA fail'))) {
                $smsListesi[] = ['to' => $telefon, 'message' => $musteriMesaji];
            }
        }

        if ($ayar && $ayar->personel == 1) {
            foreach ($personelIdleri as $personelId) {
                $personel = Personeller::find($personelId);
                if (! $personel || ! $personel->yetkili_id) {
                    continue;
                }
                $gsm = IsletmeYetkilileri::where('id', $personel->yetkili_id)->value('gsm1');
                if (empty($gsm)) {
                    continue;
                }
                $personelMesaji = $musteriAdi.' isimli müşterinin '.$hizmetAdi.' randevusu '.$tarihSaat.' olarak güncellendi.';
                if (! ($waAcik && $this->whatsappIleGitti($salon, $gsm, $personelMesaji, $randevu, null, 'suruklebirak personel WA fail'))) {
                    $smsListesi[] = ['to' => $gsm, 'message' => $personelMesaji];
                }
            }
        }

        if (count($smsListesi) > 0) {
            try {
                $this->kanal->sms($smsListesi, $randevu->salon_id);
            } catch (\Throwable $e) {
                Log::warning('suruklebirak sms_gonder_2 fail: '.$e->getMessage());
            }
        }
    }

    private function whatsappIleGitti(object $salon, mixed $telefon, string $mesaj, Randevular $randevu, int|string|null $userId, string $hataLogu): bool
    {
        try {
            $sonuc = $this->whatsapp->gonder($salon, $telefon, $mesaj, $randevu->id, $userId, null, false, 'guncelleme_bildirim');

            return ($sonuc['ok'] ?? false) === true;
        } catch (\Throwable $e) {
            Log::warning($hataLogu.': '.$e->getMessage());

            return false;
        }
    }
}
