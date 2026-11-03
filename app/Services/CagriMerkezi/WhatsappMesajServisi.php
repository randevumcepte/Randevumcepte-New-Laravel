<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\Whatsapp\WhatsappDurumu;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Çağrı merkezinden müşteriye MANUEL WhatsApp mesajı — eski cagri_whatsapp_gonder (yalnız mobil
 * kullanıyordu; v2'de web kokpitinde de var). Müşteri aranacak kaydından çözülür → personel ham
 * numarayı görmeden gönderir (KVKK).
 *
 * v2 yan etki üretmez: bağlantı ön-kontrolü (paylaşılan oturum dahil, WhatsappDurumu kuralı) burada,
 * gönderim outbox 'whatsapp_mesaj' ile eski monolitin MEVCUT WhatsAppService::sendReminder'ı
 * (salon başına Baileys/whatsmeow, günlük tavan, mesai) — gonderim tipi 'manuel_musteri'.
 */
class WhatsappMesajServisi
{
    public const MAKS_UZUNLUK = 1000;

    public function __construct(private CagriMerkeziErisimServisi $erisim) {}

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
        $this->baglantiDenetle($e->salonId);

        BildirimKuyrugu::yaz($e->salonId, 'whatsapp_mesaj', null, $e->yetkiliId, [
            'user_id' => (int) $musteri->id, 'mesaj' => mb_substr($mesaj, 0, self::MAKS_UZUNLUK), 'gonderim_tipi' => 'manuel_musteri',
        ]);
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_whatsapp_gonder', 'aranacak_musteri', $aranacakId, (string) $musteri->name ?: null,
            'Çağrı merkezinden WhatsApp mesajı gönderildi', ['user_id' => (int) $musteri->id, 'uzunluk' => mb_strlen($mesaj)]);

        return ['mesaj' => 'Mesaj gönderim kuyruğuna alındı. Birazdan müşteriye ulaşacak.'];
    }

    /** Baileys oturumu bağlı olmalı (paylaşılan oturumda üst salonun durumu — eski resolveWaSalon). */
    private function baglantiDenetle(int $salonId): void
    {
        $kolon = ['id', 'whatsapp_aktif', 'whatsapp_durum', 'whatsapp_numara', 'whatsapp_kontor', 'whatsapp_deneme_bitis', 'whatsapp_saglayici', 'whatsapp_paylasilan_salon_id'];
        $salon = DB::table('salonlar')->where('id', $salonId)->first($kolon);
        if ($salon && $salon->whatsapp_paylasilan_salon_id && (int) $salon->whatsapp_paylasilan_salon_id !== $salonId) {
            $salon = DB::table('salonlar')->where('id', $salon->whatsapp_paylasilan_salon_id)->first($kolon) ?? $salon;
        }
        $baileys = ($salon->whatsapp_saglayici ?? 'baileys') === 'baileys';
        if ($baileys && ! ($salon && WhatsappDurumu::salondan($salon, date('Y-m-d'))->bagli)) {
            throw new CakismaException('WhatsApp bağlı değil. Önce WhatsApp ayarlarından QR okutun.');
        }
    }
}
