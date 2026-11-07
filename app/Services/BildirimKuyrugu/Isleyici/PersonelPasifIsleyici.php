<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Personeller;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\OturumSonlandirici;

/** Pasife alinan personelin markada baska aktif satiri kalmadiysa oturumu kapatilir; eski ApiController::personelPasifOturumSonlandir. */
class PersonelPasifIsleyici extends PersonelTabanliIsleyici
{
    public function __construct(private OturumSonlandirici $oturum) {}

    protected function personelIsle(object $kayit, array $payload, Personeller $personel): void
    {
        $this->oturum->personelErisimiKalktiysaSonlandir(
            (int) $personel->id,
            $personel->yetkili_id !== null ? (int) $personel->yetkili_id : null,
            $personel->salon_id !== null ? (int) $personel->salon_id : null,
            BildirimTipleri::PERSONEL_PASIF,
            'Hesabınız pasife alındı',
            'Yöneticiniz hesabınızı pasife aldı. Oturumunuz sonlandırıldı, tekrar giriş yapmanız gerekiyor.'
        );
    }
}
