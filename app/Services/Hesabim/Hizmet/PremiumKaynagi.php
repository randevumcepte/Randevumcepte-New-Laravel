<?php

namespace App\Services\Hesabim\Hizmet;

use App\Domain\Hesabim\HizmetKarti;
use App\Domain\Hesabim\UyelikKatalogu;

/** Yalnız Premium pakete (uyelik_turu=3) dahil sabit hizmetler. */
class PremiumKaynagi implements HizmetKaynagi
{
    public function kartlar(object $salon): array
    {
        if (! UyelikKatalogu::premiumMu($salon->uyelik_turu ?? null)) {
            return [];
        }

        return [
            HizmetKarti::pakete('santral', 'Santral Sistemi', 'Çağrı yönetimi, arama kayıtları ve web telefon', 'fa-headphones', 'bi-headset', 'lacivert'),
            HizmetKarti::pakete('easistan', 'Asistanım', 'Yapay zekâ destekli akıllı işletme asistanı', 'fa-magic', 'bi-magic', 'pembe'),
            HizmetKarti::pakete('markaliuygulama', 'Markanıza Özel Uygulama', 'İşletmenize özel markalı mobil uygulama', 'fa-mobile', 'bi-phone', 'mor'),
            HizmetKarti::pakete('anket', 'Memnuniyet Anketi', 'Randevu sonrası otomatik memnuniyet anketi', 'fa-star', 'bi-star', 'teal'),
        ];
    }
}
