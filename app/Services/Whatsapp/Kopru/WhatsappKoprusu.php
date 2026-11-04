<?php

namespace App\Services\Whatsapp\Kopru;

/**
 * WhatsApp oturum köprüsü sözleşmesi — servisler sağlayıcıyı bilmez (bugün whatsmeow; değişirse
 * yalnız bağlama AppServiceProvider'da değişir, testler sahte köprü verir). Hata fırlatmaz:
 * ulaşılamazsa KopruCevabi(kod: 0) döner, iş kararı çağıranda.
 */
interface WhatsappKoprusu
{
    public function baslat(int $oturumId): KopruCevabi;

    public function durum(int $oturumId): KopruCevabi;

    public function qr(int $oturumId): KopruCevabi;

    public function cikis(int $oturumId): KopruCevabi;

    /** Telefon numarasıyla eşleştirme kodu (QR alternatifi). $telefon yalnız rakam, 90'lı. */
    public function eslestir(int $oturumId, string $telefon): KopruCevabi;
}
