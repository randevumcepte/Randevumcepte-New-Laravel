<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use App\Services\BildirimKuyrugu\Kanal\WhatsappMesajFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Grup dersi bildirimi; eski DersBildirimServisi::musteriyeGonder. WhatsApp oncelikli (musteri onayi aranmaz), basarisiz ya da
 * kapaliysa salon adi basa eklenerek SMS. WA ve SMS hatalari yutulur, kayit durum=1 olur.
 */
class DersBildirimIsleyici implements BildirimIsleyici
{
    public function __construct(private WhatsappKanali $whatsapp, private SmsKanali $sms) {}

    public function isle(object $kayit, array $payload): void
    {
        $musteri = DB::table('users')->where('id', (int) ($payload['user_id'] ?? 0))->first();
        $salon = $this->whatsapp->salon((int) $kayit->salon_id);
        if (! $musteri || ! $salon || trim((string) ($payload['mesaj'] ?? '')) === '') {
            throw new RuntimeException('ders_bildirim: musteri/salon/mesaj yok');
        }

        $this->musteriyeGonder($salon, $musteri, (string) $payload['mesaj'], $payload['gonderim_tipi'] ?? 'ders_bildirim');
    }

    private function musteriyeGonder(object $salon, object $musteri, string $mesaj, string $gonderimTipi): void
    {
        if (empty($musteri->cep_telefon)) {
            return;
        }

        $oturumSalonu = $this->whatsapp->oturumSalonu($salon);
        $kanalAcik = $this->whatsapp->kanalAcikMi($oturumSalonu);
        Log::info('[DERS-BILD] kanal karar', [
            'salon_id'            => $salon->id,
            'wa_session_salon_id' => $oturumSalonu->id ?? null,
            'musteri_id'          => $musteri->id,
            'saglayici'           => $oturumSalonu->whatsapp_saglayici ?? 'baileys',
            'wa_aktif'            => (int) ($oturumSalonu->whatsapp_aktif ?? 0),
            'wa_durum'            => $oturumSalonu->whatsapp_durum ?? null,
            'wa_kanali_acik'      => $kanalAcik,
            'gonderim_tipi'       => $gonderimTipi,
        ]);

        $waBasarili = false;
        if ($kanalAcik) {
            try {
                $metin = WhatsappMesajFormat::uygulamaDavetiEk($mesaj, $salon, $musteri->id);
                $sonuc = $this->whatsapp->gonder($salon, $musteri->cep_telefon, $metin, null, $musteri->id, null, false, $gonderimTipi);
                $waBasarili = $sonuc['ok'] ?? false;
                Log::info('[DERS-BILD] WA sonuc', ['salon_id' => $salon->id, 'wa_ok' => $waBasarili, 'sonuc' => $sonuc]);
                if (! $waBasarili) {
                    Log::warning('[DERS-BILD] WA basarisiz -> SMS fallback', ['salon_id' => $salon->id, 'error' => $sonuc['error'] ?? 'unknown']);
                }
            } catch (\Throwable $e) {
                Log::warning('[DERS-BILD] WA exception -> SMS fallback', ['salon_id' => $salon->id, 'err' => $e->getMessage()]);
            }
        }

        if (! $waBasarili) {
            // SMS'te gonderici kimligi gorunmez; salon adi basa eklenir.
            $smsMetni = ! empty($salon->salon_adi) ? $salon->salon_adi.' - '.$mesaj : $mesaj;
            try {
                $this->sms->gonder($salon->id, [['to' => $musteri->cep_telefon, 'message' => $smsMetni]]);
            } catch (\Throwable $e) {
                Log::warning('[DERS-BILD] SMS fail', ['salon_id' => $salon->id, 'err' => $e->getMessage()]);
            }
        }
    }
}
