<?php

namespace App\Services\BildirimKuyrugu;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Takvim tek yonlu aktarimi; eski App\Services\GoogleCalendarService::syncRandevuHizmet ve removeRandevuHizmet ile birebir.
 * Eski kod da paket kullanmaz, dogrudan HTTP'dir; yeni composer paketi gerekmez. OAuth baglama akisi eski panelde kalir,
 * tablolar ortaktir. Eskideki gibi yalniz \Exception yakalanir, baglanti basina hata digerlerini durdurmaz.
 */
class GoogleTakvimServisi
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const TAKVIM_KOK = 'https://www.googleapis.com/calendar/v3';

    private const SAAT_DILIMI = 'Europe/Istanbul';

    private const ZAMAN_ASIMI_SN = 15;

    /**
     * Bir randevu hizmetini salondaki ilgili baglantilara yazar. Baglantiyi yapan yetkilinin salon_personelleri satiri varsa
     * yalniz kendi atandigi randevular, yoksa (hesap sahibi) tum randevular.
     *
     * @param  object  $rh  randevu_hizmetler satiri
     */
    public function randevuHizmetiYaz(object $rh): bool
    {
        $randevu = DB::table('randevular')->where('id', $rh->randevu_id)->first();
        if (! $randevu) {
            return false;
        }

        $baglantilar = DB::table('google_calendar_baglantilar')->where('salon_id', $randevu->salon_id)->where('aktif', 1)->get();
        if ($baglantilar->isEmpty()) {
            return false;
        }

        $etkinlik = $this->etkinlik($rh, $randevu);
        $basarili = false;

        foreach ($baglantilar as $baglanti) {
            $personel = DB::table('salon_personelleri')->where('yetkili_id', $baglanti->yetkili_id)->where('salon_id', $randevu->salon_id)->first();
            if ($personel && (int) $rh->personel_id !== (int) $personel->id) {
                continue;
            }

            $esleme = DB::table('google_calendar_event_eslemeleri')->where('baglanti_id', $baglanti->id)->where('randevu_hizmet_id', $rh->id)->first();

            try {
                if ($esleme) {
                    $this->etkinlikGuncelle($baglanti, $esleme->google_event_id, $etkinlik);
                    DB::table('google_calendar_event_eslemeleri')->where('id', $esleme->id)->update(['son_sync_zamani' => now(), 'updated_at' => now()]);
                    $basarili = true;
                } else {
                    $olusan = $this->etkinlikEkle($baglanti, $etkinlik);
                    if (! empty($olusan['id'])) {
                        DB::table('google_calendar_event_eslemeleri')->insert([
                            'baglanti_id'       => $baglanti->id,
                            'randevu_hizmet_id' => $rh->id,
                            'google_event_id'   => $olusan['id'],
                            'son_sync_zamani'   => now(),
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                        $basarili = true;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('[GoogleCalendar] sync hata', ['rh_id' => $rh->id, 'baglanti_id' => $baglanti->id, 'hata' => $e->getMessage()]);
            }
        }

        return $basarili;
    }

    /** Randevu hizmetinin Google etkinliklerini siler; silinemese de esleme kalkar (eskisi gibi). */
    public function randevuHizmetiSil(int $rhId): void
    {
        foreach (DB::table('google_calendar_event_eslemeleri')->where('randevu_hizmet_id', $rhId)->get() as $esleme) {
            $baglanti = DB::table('google_calendar_baglantilar')->where('id', $esleme->baglanti_id)->first();
            if ($baglanti) {
                try {
                    $this->etkinlikSil($baglanti, $esleme->google_event_id);
                } catch (\Exception $e) {
                    Log::warning('[GoogleCalendar] delete hata', ['rh_id' => $rhId, 'hata' => $e->getMessage()]);
                }
            }
            DB::table('google_calendar_event_eslemeleri')->where('id', $esleme->id)->delete();
        }
    }

    /** Eski buildEventFromRandevuHizmet. Kaynak adresi eskide url('/'), yani eski panel. */
    private function etkinlik(object $rh, object $randevu): array
    {
        $baslangic = Carbon::parse($randevu->tarih.' '.($rh->saat ?: $randevu->saat), self::SAAT_DILIMI);
        $bitis = (clone $baslangic)->addMinutes((int) ($rh->sure_dk ?: 15));

        $musteriAd = DB::table('users')->where('id', $randevu->user_id)->value('name') ?? ('Musteri #'.$randevu->user_id);
        $hizmetAd = DB::table('hizmetler')->where('id', $rh->hizmet_id)->value('hizmet_adi') ?? '';

        $aciklama = [];
        if (! empty($randevu->notlar)) {
            $aciklama[] = 'Musteri notu: '.$randevu->notlar;
        }
        if (! empty($randevu->personel_notu)) {
            $aciklama[] = 'Personel notu: '.$randevu->personel_notu;
        }
        $aciklama[] = 'Randevu #'.$randevu->id;

        return [
            'summary'     => trim($musteriAd.($hizmetAd ? (' — '.$hizmetAd) : '')),
            'description' => implode("\n", $aciklama),
            'start'       => ['dateTime' => $baslangic->toRfc3339String(), 'timeZone' => self::SAAT_DILIMI],
            'end'         => ['dateTime' => $bitis->toRfc3339String(), 'timeZone' => self::SAAT_DILIMI],
            'extendedProperties' => [
                'private' => [
                    'randevumcepte_randevu_id'        => (string) $randevu->id,
                    'randevumcepte_randevu_hizmet_id' => (string) $rh->id,
                ],
            ],
            'source' => ['title' => 'Randevumcepte', 'url' => rtrim((string) config('panel.eski_panel_url'), '/')],
        ];
    }

    private function etkinlikEkle(object $baglanti, array $etkinlik): ?array
    {
        $this->belirteciTazele($baglanti);

        return $this->cevabiIsle($this->istek($baglanti)->withBody((string) json_encode($etkinlik), 'application/json')->post($this->etkinlikAdresi($baglanti)), $baglanti);
    }

    private function etkinlikGuncelle(object $baglanti, string $etkinlikId, array $etkinlik): void
    {
        $this->belirteciTazele($baglanti);

        $this->cevabiIsle($this->istek($baglanti)->withBody((string) json_encode($etkinlik), 'application/json')->patch($this->etkinlikAdresi($baglanti).'/'.rawurlencode($etkinlikId)), $baglanti);
    }

    /** 404 (zaten silinmis) ve 410 (gone) basarili sayilir. */
    private function etkinlikSil(object $baglanti, string $etkinlikId): void
    {
        $this->belirteciTazele($baglanti);

        $cevap = $this->istek($baglanti)->delete($this->etkinlikAdresi($baglanti).'/'.rawurlencode($etkinlikId));
        if (! in_array($cevap->status(), [200, 204, 404, 410], true)) {
            $this->cevabiIsle($cevap, $baglanti);
        }
    }

    /** Eski refreshIfNeeded: suresi dolmus access_token'i refresh_token ile tazeler, satira yazar. */
    private function belirteciTazele(object $baglanti): void
    {
        if ($baglanti->expires_at && Carbon::parse($baglanti->expires_at)->isFuture()) {
            return;
        }
        if (! $baglanti->refresh_token) {
            throw new RuntimeException('refresh_token yok, yeniden baglanmak gerekli');
        }

        $cevap = $this->http()->asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('bildirim_kuyrugu.google_takvim.client_id'),
            'client_secret' => config('bildirim_kuyrugu.google_takvim.client_secret'),
            'refresh_token' => $baglanti->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);
        $govde = $cevap->json();
        if ($cevap->status() !== 200 || ! isset($govde['access_token'])) {
            throw new RuntimeException('refresh basarisiz: '.$cevap->body());
        }

        $baglanti->access_token = $govde['access_token'];
        $baglanti->expires_at = Carbon::now()->addSeconds(((int) ($govde['expires_in'] ?? 3600)) - 60)->toDateTimeString();
        if (! empty($govde['refresh_token'])) {
            $baglanti->refresh_token = $govde['refresh_token']; // Google bazen yeni refresh dondurur
        }
        DB::table('google_calendar_baglantilar')->where('id', $baglanti->id)->update([
            'access_token'  => $baglanti->access_token,
            'expires_at'    => $baglanti->expires_at,
            'refresh_token' => $baglanti->refresh_token,
            'updated_at'    => now(),
        ]);
    }

    /** Eski handleResp: hata baglantiya yazilir ve firlatilir. */
    private function cevabiIsle(Response $cevap, object $baglanti): ?array
    {
        $kod = $cevap->status();
        $govde = $cevap->body();
        if ($kod >= 200 && $kod < 300) {
            return json_decode($govde, true);
        }

        $baglanti->son_hata_zamani = Carbon::now()->toDateTimeString();
        $baglanti->son_hata_mesaji = substr('HTTP '.$kod.' '.$govde, 0, 2000);
        DB::table('google_calendar_baglantilar')->where('id', $baglanti->id)->update([
            'son_hata_zamani' => $baglanti->son_hata_zamani,
            'son_hata_mesaji' => $baglanti->son_hata_mesaji,
            'updated_at'      => now(),
        ]);

        throw new RuntimeException('Google API hata: HTTP '.$kod.' '.$govde);
    }

    private function etkinlikAdresi(object $baglanti): string
    {
        return self::TAKVIM_KOK.'/calendars/'.rawurlencode($baglanti->calendar_id).'/events';
    }

    private function istek(object $baglanti): PendingRequest
    {
        return $this->http()->withToken($baglanti->access_token);
    }

    /** Eski Guzzle istemcisi timeout=15 ile tek sinir kullanir; Laravel'in 10 sn baglanti varsayilani o yuzden esitlenir. */
    private function http(): PendingRequest
    {
        return Http::timeout(self::ZAMAN_ASIMI_SN)->connectTimeout(self::ZAMAN_ASIMI_SN);
    }
}
