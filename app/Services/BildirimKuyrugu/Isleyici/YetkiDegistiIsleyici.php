<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Personeller;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;

/** Yetkisi degisen personele force_logout push'u; eski ApiController::personelYetkiDegistiBildir. Hata yutulmaz (yeniden denenir). */
class YetkiDegistiIsleyici extends PersonelTabanliIsleyici
{
    protected function personelIsle(object $kayit, array $payload, Personeller $personel): void
    {
        PushBildirimi::personele((int) $personel->id, (int) $personel->salon_id)
            ->tip(BildirimTipleri::YETKI_DEGISTI)
            ->baslik('Yetkileriniz Güncellendi')
            ->govde('Yöneticiniz yetkilerinizi güncelledi. Devam etmek için tekrar giriş yapmanız gerekiyor.')
            ->ekstra(['force_logout' => '1'])
            ->gonder();
    }
}
