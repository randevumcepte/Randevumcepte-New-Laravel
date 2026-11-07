<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\AdisyonPaketSeanslar;
use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Services\BildirimKuyrugu\Randevu\RandevuVerisi;
use App\Services\BildirimKuyrugu\Randevu\SeansOzeti;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * seans_kullanim: eski SeansBildirimService::bilgilendir. Kapi: salon_sms_ayarlari ayar_id=20 musteri (varsayilan kapali).
 * Uc kanal: push (detayli), WhatsApp (detayli), SMS (kisa; yalniz push VE WhatsApp ulasmadiysa).
 * Son 5 dk icinde ayni randevuya seans_bildirim WhatsApp'i gittiyse atlanir. Kanal hatalari yutulur, kayit yeniden denenmez.
 */
class SeansKullanimIsleyici extends RandevuTabanliIsleyici
{
    private const AYAR_ID = 20;

    private const GONDERIM_TIPI = 'seans_bildirim';

    public function __construct(
        private RandevuVerisi $veri,
        private SeansOzeti $ozet,
        private WhatsappKanali $whatsapp,
        private SmsKanali $sms,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $baglam = ['randevu_id' => $randevu->id];

        if (! $this->veri->ayarAcikMi($randevu->salon_id, self::AYAR_ID, 'musteri')) {
            Log::info('[SEANS-KULLANIM] Seans Bilgisi Bildirimi kapali, atlandi', $baglam + ['salon_id' => $randevu->salon_id]);

            return;
        }
        if ($this->sonBesDakikadaGitti($randevu)) {
            Log::info('[SEANS-KULLANIM] duplicate — son 5dk icinde zaten gonderildi, atlandi', $baglam + ['salon_id' => $randevu->salon_id]);

            return;
        }

        $musteri = $randevu->users;
        if (! $musteri || ! $musteri->id) {
            Log::info('[SEANS-KULLANIM] musteri bulunamadi, atlandi', $baglam);

            return;
        }
        $musteriIsmi = trim($musteri->name ?? '') ?: 'Müşterimiz';

        // Isaretlenmeyen satirlar silinmistir; kalanlar bu randevuda kullanilanlar.
        $seanslar = AdisyonPaketSeanslar::where('randevu_id', $randevu->id)->get();
        if ($seanslar->isEmpty()) {
            Log::info('[SEANS-KULLANIM] randevuya bagli seans yok, atlandi', $baglam);

            return;
        }

        $bloklar = [];
        foreach ($seanslar->pluck('adisyon_paket_id')->filter()->unique()->values() as $paketId) {
            $bloklar[] = $this->ozet->paketBloku((int) $paketId, (int) $randevu->id);
        }
        foreach ($seanslar->pluck('adisyon_hizmet_id')->filter()->unique()->values() as $hizmetId) {
            $bloklar[] = $this->ozet->hizmetBloku((int) $hizmetId, (int) $randevu->id);
        }
        $bloklar = array_values(array_filter($bloklar, fn ($b) => $b !== null));
        if (empty($bloklar)) {
            Log::info('[SEANS-KULLANIM] ozet uretilemedi, atlandi', $baglam);

            return;
        }

        $govde = $this->ozet->mesaj($musteriIsmi, $bloklar);
        $salon = $this->veri->salon($randevu->salon_id);
        $telefon = trim((string) ($musteri->cep_telefon ?? ''));

        $pushUlasti = $this->pushUlasti($randevu, $musteri->id, $govde);
        $waUlasti = $salon && $telefon !== '' && $this->whatsappUlasti($salon, $telefon, $govde, $randevu, $musteri->id);

        if (! $pushUlasti && ! $waUlasti && $salon && $telefon !== '') {
            $this->kisaSms($salon, $telefon, $musteriIsmi, $randevu);
        }
    }

    /** Hata olursa akis devam eder (fail-open). */
    private function sonBesDakikadaGitti(Randevular $randevu): bool
    {
        try {
            return DB::table('whatsapp_gonderim_loglari')
                ->where('salon_id', $randevu->salon_id)
                ->where('randevu_id', $randevu->id)
                ->where('gonderim_tipi', self::GONDERIM_TIPI)
                ->where('created_at', '>', now()->subMinutes(5))
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('[SEANS-KULLANIM] idempotency check hata: '.$e->getMessage());

            return false;
        }
    }

    private function pushUlasti(Randevular $randevu, int|string $musteriId, string $govde): bool
    {
        try {
            $sonuc = PushBildirimi::musteriye((int) $musteriId, (int) $randevu->salon_id)
                ->tip(BildirimTipleri::SESSION_USED)
                ->baslik('Seans Bilgilendirmesi ✨')
                ->govde($govde)
                ->randevu((int) $randevu->id)
                ->deepLink('sessions')
                ->gonder();
            $ulasti = (int) ($sonuc['sent'] ?? 0) > 0;
            Log::info('[SEANS-KULLANIM] push', ['randevu_id' => $randevu->id, 'ulasti' => $ulasti, 'sonuc' => $sonuc]);

            return $ulasti;
        } catch (\Throwable $e) {
            Log::warning('[SEANS-KULLANIM] push fail', ['randevu_id' => $randevu->id, 'err' => $e->getMessage()]);

            return false;
        }
    }

    /** WhatsApp kalin bicimi tek yildizdir: ** -> *. */
    private function whatsappUlasti(object $salon, string $telefon, string $govde, Randevular $randevu, int|string $musteriId): bool
    {
        try {
            $sonuc = $this->whatsapp->gonder($salon, $telefon, str_replace('**', '*', $govde), (int) $randevu->id, (int) $musteriId, null, false, self::GONDERIM_TIPI);
            $ulasti = ! empty($sonuc['ok']);
            Log::info('[SEANS-KULLANIM] whatsapp', ['randevu_id' => $randevu->id, 'ulasti' => $ulasti, 'sonuc' => $sonuc]);

            return $ulasti;
        } catch (\Throwable $e) {
            Log::warning('[SEANS-KULLANIM] whatsapp fail', ['randevu_id' => $randevu->id, 'err' => $e->getMessage()]);

            return false;
        }
    }

    private function kisaSms(object $salon, string $telefon, string $musteriIsmi, Randevular $randevu): void
    {
        try {
            $yerel = $this->yerelTelefon($telefon);
            if ($yerel !== '') {
                $this->sms->gonder((string) $salon->id, [
                    ['to' => $yerel, 'message' => "Sayın {$musteriIsmi}, seans bilgileriniz güncellendi. Sağlıklı günler dileriz."],
                ]);
                Log::info('[SEANS-KULLANIM] sms fallback', ['randevu_id' => $randevu->id, 'to' => $yerel]);
            }
        } catch (\Throwable $e) {
            Log::warning('[SEANS-KULLANIM] sms fail', ['randevu_id' => $randevu->id, 'err' => $e->getMessage()]);
        }
    }

    /** Eski telYerel: +90/90/0 kirpilir, 5xxxxxxxxx. */
    private function yerelTelefon(string $telefon): string
    {
        $rakam = preg_replace('/\D/', '', $telefon);
        $rakam = preg_replace('/^90/', '', $rakam);

        return ltrim($rakam, '0');
    }
}
