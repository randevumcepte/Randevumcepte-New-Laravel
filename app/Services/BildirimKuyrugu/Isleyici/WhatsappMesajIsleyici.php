<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\WhatsappKanali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Manuel WhatsApp (Cagri Merkezi, musteri karti). SMS'e dusmez. Kalici hatalar (gecersiz numara, tavan, mesai disi) tekrar
 * denenmez, log yazilir ve kayit durum=1 olur; yalniz servise ulasilamazsa yeniden denenir.
 */
class WhatsappMesajIsleyici implements BildirimIsleyici
{
    public function __construct(private WhatsappKanali $whatsapp) {}

    public function isle(object $kayit, array $payload): void
    {
        $musteri = DB::table('users')->where('id', (int) ($payload['user_id'] ?? 0))->first();
        $salon = $this->whatsapp->salon((int) $kayit->salon_id);
        if (! $musteri || empty($musteri->cep_telefon) || ! $salon || trim((string) ($payload['mesaj'] ?? '')) === '') {
            throw new RuntimeException('whatsapp_mesaj: musteri/telefon/salon/mesaj yok');
        }

        $sonuc = $this->whatsapp->gonder($salon, $musteri->cep_telefon, (string) $payload['mesaj'],
            null, $musteri->id, null, false, $payload['gonderim_tipi'] ?? 'manuel_musteri');
        if (empty($sonuc['ok'])) {
            if (($sonuc['error'] ?? '') === 'service-unreachable') {
                throw new RuntimeException('whatsapp_mesaj: servis erisilemedi');
            }
            Log::warning('[v2:bildirim-isle] whatsapp_mesaj gonderilemedi', ['id' => $kayit->id, 'hata' => $sonuc['error'] ?? 'unknown']);
        }
    }
}
