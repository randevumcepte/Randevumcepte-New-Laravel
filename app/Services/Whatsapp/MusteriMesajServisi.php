<?php

namespace App\Services\Whatsapp;

use App\Domain\Whatsapp\GonderimEngeli;
use App\Domain\Whatsapp\IsletmeLinkleri;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\CagriMerkezi\WhatsappMesajServisi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Müşteri kartı ve takvimden müşteriye MANUEL WhatsApp mesajı — eski musteri-whatsapp-durum ve
 * musteri-manuel-whatsapp. Numara sunucuda çözülür, istemciye gitmez. Metinler eski uçla aynıdır.
 *
 * Bağlantı kuralı eski uçla aynı: salonun KENDİ durumu okunur, paylaşılan oturumlu alt salon "bağlı değil"
 * görür. Gönderim outbox `whatsapp_mesaj` ile eski monolitin sendReminder'ından gider.
 */
class MusteriMesajServisi
{
    private const BAILEYS = 'baileys';

    private const CLOUD_API = 'cloud_api';

    public function __construct(private WhatsappOturumServisi $oturum) {}

    /** Alanlar beyaz listedir: sağlayıcı anahtarı ve numara dönmez. */
    public function durum(int $salonId, ?int $userId): array
    {
        $salon = $this->salon($salonId);

        return ['bagli' => $this->bagli($salon)] + IsletmeLinkleri::sun($salon) + ['musteri_onay' => $userId ? $this->onay($salonId, $userId) : null];
    }

    public function gonder(int $salonId, int $userId, int $yetkiliId, ?string $mesaj): array
    {
        $mesaj = trim((string) $mesaj);
        if ($mesaj === '') {
            throw new GecersizIslemException('Mesaj bos olamaz.');
        }
        if (mb_strlen($mesaj) > WhatsappMesajServisi::MAKS_UZUNLUK) {
            throw new GecersizIslemException('Mesaj en fazla '.WhatsappMesajServisi::MAKS_UZUNLUK.' karakter olabilir.');
        }

        $salon = $this->salon($salonId);
        $portfoy = DB::table('musteri_portfoy')->where('user_id', $userId)->where('salon_id', $salonId)->first(['kara_liste']);
        if (! $portfoy) {
            throw new GecersizIslemException('Bu musteri salonunuza ait degil.');
        }
        $musteri = DB::table('users')->where('id', $userId)->first(['id', 'name', 'cep_telefon']);
        if (! $musteri || trim((string) $musteri->cep_telefon) === '') {
            throw new GecersizIslemException('Musteri telefon numarasi bulunamadi.');
        }
        if (($salon->whatsapp_saglayici ?: self::BAILEYS) === self::BAILEYS && ! $this->bagli($salon)) {
            throw new CakismaException('WhatsApp bagli degil. Once WhatsApp ayarlarindan QR okutun.');
        }
        // Eski monolit kara listedeki müşteriye göndermez; sonuç ekranda görünsün.
        if ((int) $portfoy->kara_liste === 1 && (int) ($salon->kara_liste_aktif ?? 1) === 1) {
            throw new GecersizIslemException(GonderimEngeli::metin(null));
        }
        if ($engel = $this->oturum->gonderimEngeli($salonId)) {
            throw new CakismaException(GonderimEngeli::metin($engel));
        }

        BildirimKuyrugu::whatsappMesaji($salonId, $yetkiliId, $userId, $mesaj);
        AktiviteLog::yaz($salonId, $yetkiliId, 'musteri_whatsapp_gonder', 'musteri', $userId, (string) $musteri->name ?: null,
            'Müşteriye WhatsApp mesajı gönderildi', ['user_id' => $userId, 'uzunluk' => mb_strlen($mesaj)]);

        return ['mesaj' => 'Mesaj gonderim kuyruguna alindi.'];
    }

    private function salon(int $salonId): object
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first();
        if (! $salon) {
            throw new GecersizIslemException('Salon bulunamadi.');
        }

        return $salon;
    }

    private function bagli(object $salon): bool
    {
        if (($salon->whatsapp_saglayici ?: self::BAILEYS) === self::CLOUD_API) {
            return ! empty($salon->cloud_api_token) && ! empty($salon->cloud_api_phone_number_id);
        }

        return (int) ($salon->whatsapp_aktif ?? 0) === 1 && (string) ($salon->whatsapp_durum ?? '') === 'connected';
    }

    /** Yalnız salonun müşterisi için; başka salonun müşterisinin bilgisi okunmaz. */
    private function onay(int $salonId, int $userId): ?int
    {
        if (! DB::table('musteri_portfoy')->where('user_id', $userId)->where('salon_id', $salonId)->exists()) {
            return null;
        }

        return (int) DB::table('users')->where('id', $userId)->value('whatsapp_onay');
    }
}
