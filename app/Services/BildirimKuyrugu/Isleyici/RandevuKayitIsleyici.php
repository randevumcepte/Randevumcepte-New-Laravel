<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Randevu\RandevuKayitGonderimi;
use App\Services\BildirimKuyrugu\Randevu\RandevuKayitMesajlari;
use Illuminate\Http\Request;

/**
 * randevu_kayit: eski V2BildirimIsle randevu_kayit dali. payload.istek: randevu_tarihi, randevu_saati, randevuKaynak, durum,
 * olusturan. payload.randevu_idler: tekrarli kayitta birden cok randevu; mesajlar toplanip tek seferde gonderilir, push yalniz son randevu icin.
 */
class RandevuKayitIsleyici extends RandevuTabanliIsleyici
{
    public function __construct(
        private RandevuKayitMesajlari $mesajlari,
        private RandevuKayitGonderimi $gonderim,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $istek = new Request((array) ($payload['istek'] ?? []));
        $guncelleme = ! empty($payload['guncelleme']);
        $zamanDegisti = ! empty($payload['zaman_degisti']);
        $mesajlar = [];
        $sonId = null;

        foreach ((array) ($payload['randevu_idler'] ?? [$randevu->id]) as $randevuId) {
            $r = Randevular::find((int) $randevuId);
            if (! $r) {
                continue;
            }
            array_push($mesajlar, ...$this->mesajlari->hazirla($istek, $r, $guncelleme, $zamanDegisti, ! empty($payload['hizmet_degisti']), $guncelleme ? true : null));
            $sonId = $r->id;
        }

        $this->gonderim->gonder($mesajlar, $kayit->salon_id, $sonId, $istek->input('randevuKaynak', 'salon'), $guncelleme, $zamanDegisti);
    }
}
