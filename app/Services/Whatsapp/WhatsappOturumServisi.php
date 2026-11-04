<?php

namespace App\Services\Whatsapp;

use App\Domain\Whatsapp\WhatsappDurumu;
use Illuminate\Support\Facades\DB;

/**
 * Bir salonun WhatsApp OTURUMU — paylaşılan oturum çözümüyle (tek yer). Header rozeti
 * (Kabuk\IletisimDurumServisi) ve gönderim ön-kontrolleri (ör. Çağrı Merkezi WhatsApp mesajı)
 * bunu kullanır; eski gönderim kodu (WhatsAppService::resolveWaSalon) ile aynı salonu okur:
 * bağlantı, numara, kontör ve deneme bitişi oturum SAHİBİ salondan.
 */
class WhatsappOturumServisi
{
    private const KOLONLAR = ['id', 'salon_adi', 'whatsapp_aktif', 'whatsapp_durum', 'whatsapp_numara', 'whatsapp_kontor',
        'whatsapp_deneme_bitis', 'whatsapp_saglayici', 'whatsapp_paylasilan_salon_id', 'whatsapp_baglanti_tarihi', 'whatsapp_gunluk_limit'];

    /**
     * oturum: mesajların gerçekte gittiği salonun satırı (KOLONLAR).
     *
     * @return array{durum: WhatsappDurumu, saglayici: string, paylasilan: ?array{salon_id:int, salon_adi:string}, oturum: object}|null
     */
    public function coz(int $salonId): ?array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first(self::KOLONLAR);
        if (! $salon) {
            return null;
        }
        $sahipId = WhatsappDurumu::oturumSalonId($salonId, $salon->whatsapp_paylasilan_salon_id);
        $sahip = $sahipId !== $salonId ? DB::table('salonlar')->where('id', $sahipId)->first(self::KOLONLAR) : null;
        $oturum = $sahip ?? $salon;   // üst salon silinmişse kendisi (eski resolveWaSalon ile aynı)

        return [
            'durum'      => WhatsappDurumu::salondan($oturum, date('Y-m-d')),
            'saglayici'  => (string) ($oturum->whatsapp_saglayici ?: 'baileys'),
            'paylasilan' => $sahip ? ['salon_id' => (int) $sahip->id, 'salon_adi' => (string) $sahip->salon_adi] : null,
            'oturum'     => $oturum,
        ];
    }

    /** Baileys oturumu ise bağlı olmalı (whatsmeow/diğer sağlayıcılarda kontrol gönderimde). */
    public function gonderimeHazirMi(int $salonId): bool
    {
        $o = $this->coz($salonId);

        return $o !== null && ($o['saglayici'] !== 'baileys' || $o['durum']->bagli);
    }
}
