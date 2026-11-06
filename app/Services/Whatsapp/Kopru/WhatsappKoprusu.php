<?php

namespace App\Services\Whatsapp\Kopru;

/**
 * WhatsApp oturum köprüsü sözleşmesi — servisler sağlayıcıyı bilmez (bugün whatsmeow; değişirse
 * yalnız bağlama AppServiceProvider'da değişir, testler sahte köprü verir). Hata fırlatmaz:
 * ulaşılamazsa KopruCevabi(kod: 0) döner, iş kararı çağıranda. Oturum: salon id ya da sistem oturumu ("sistem-…").
 */
interface WhatsappKoprusu
{
    public function baslat(int|string $oturumId): KopruCevabi;

    public function durum(int|string $oturumId): KopruCevabi;

    public function qr(int|string $oturumId): KopruCevabi;

    public function cikis(int|string $oturumId): KopruCevabi;

    /** Telefon numarasıyla eşleştirme kodu (QR alternatifi). $telefon yalnız rakam, 90'lı. */
    public function eslestir(int|string $oturumId, string $telefon): KopruCevabi;

    /** Tek mesaj. Isletme bildirimleri outbox'tan gider; bunu yalniz sistem oturumu kullanir. */
    public function gonder(int|string $oturumId, string $telefon, string $mesaj): KopruCevabi;
}
