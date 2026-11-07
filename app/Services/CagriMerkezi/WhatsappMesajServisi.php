<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\Whatsapp\WhatsappOturumServisi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Çağrı merkezinden müşteriye MANUEL WhatsApp mesajı — eski cagri_whatsapp_gonder (yalnız mobil
 * kullanıyordu; v2'de web kokpitinde de var). Müşteri aranacak kaydından çözülür → personel ham
 * numarayı görmeden gönderir (KVKK).
 *
 * v2 yan etki üretmez: bağlantı ön-kontrolü (paylaşılan oturum dahil — Whatsapp\WhatsappOturumServisi) burada,
 * gönderim outbox 'whatsapp_mesaj' ile eski monolitin MEVCUT WhatsAppService::sendReminder'ı
 * (salon başına Baileys/whatsmeow, günlük tavan, mesai) — gonderim tipi 'manuel_musteri'.
 */
class WhatsappMesajServisi
{
    public const MAKS_UZUNLUK = 1000;

    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private WhatsappOturumServisi $oturum,
    ) {}

    public function gonder(CagriMerkeziErisim $e, int $aranacakId, string $mesaj): array
    {
        $mesaj = trim($mesaj);
        if ($mesaj === '') {
            throw new GecersizIslemException('Mesaj boş olamaz.');
        }
        $k = $this->erisim->kayit($aranacakId, $e);
        $musteri = DB::table('users')->where('id', $k->user_id)->first(['id', 'name', 'cep_telefon']);
        if (! $musteri || trim((string) $musteri->cep_telefon) === '') {
            throw new GecersizIslemException('Müşteri telefon numarası bulunamadı.');
        }
        if (! $this->oturum->gonderimeHazirMi($e->salonId)) {
            throw new CakismaException('WhatsApp bağlı değil. Önce WhatsApp ayarlarından QR okutun.');
        }

        BildirimKuyrugu::whatsappMesaji($e->salonId, $e->yetkiliId, (int) $musteri->id, mb_substr($mesaj, 0, self::MAKS_UZUNLUK));
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_whatsapp_gonder', 'aranacak_musteri', $aranacakId, (string) $musteri->name ?: null,
            'Çağrı merkezinden WhatsApp mesajı gönderildi', ['user_id' => (int) $musteri->id, 'uzunluk' => mb_strlen($mesaj)]);

        return ['mesaj' => 'Mesaj gönderim kuyruğuna alındı. Birazdan müşteriye ulaşacak.'];
    }
}
