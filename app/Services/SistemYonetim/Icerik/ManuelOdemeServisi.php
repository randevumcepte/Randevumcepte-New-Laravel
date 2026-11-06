<?php

namespace App\Services\SistemYonetim\Icerik;

use App\Domain\SistemYonetim\ManuelOdemeSatirlari;
use App\Exceptions\GecersizIslemException;
use App\Services\SistemYonetim\Denetim;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;

/** Serbest kalemli odeme formu uretir. Odeme sayfasi ve PayTR akisi eski panelde: burada yalniz form ve link. */
class ManuelOdemeServisi
{
    /** musteri_formlari.durum_id: odeme bekleniyor */
    private const ODEME_BEKLENIYOR = 6;

    /** @return array{form_id:int,link:string,toplam:float} */
    public function olustur(object $salon, array $hamSatirlar, ?string $notlar): array
    {
        ['satirlar' => $satirlar, 'hatali' => $hatali] = ManuelOdemeSatirlari::ayristir($hamSatirlar);
        if ($hatali) {
            throw new GecersizIslemException('Hizmet satırı hatalı (sıra: '.implode(', ', $hatali).'): ad ve 1.500,50 biçiminde tutar zorunlu.');
        }
        if (! $satirlar) {
            throw new GecersizIslemException('En az bir geçerli hizmet satırı girin (ad ve tutar zorunlu).');
        }

        $formId = DB::transaction(function () use ($salon, $satirlar, $notlar) {
            $formId = ZamanliKayit::ekle('musteri_formlari', [
                'salon_id' => $salon->id, 'durum_id' => self::ODEME_BEKLENIYOR, 'satis_tarihi' => now(), 'notlar' => $notlar,
            ]);
            ZamanliKayit::topluEkle('musteri_formlari_hizmetler', array_map(fn (array $s) => [
                'form_id' => $formId, 'uyelik_id' => null, 'aciklama' => $s['ad'], 'adet' => $s['adet'], 'ucret' => $s['tutar'],
            ], $satirlar));

            return $formId;
        });

        $toplam = ManuelOdemeSatirlari::toplam($satirlar);
        Denetim::yaz('manuel_odeme_linki', 'musteri_formu', $formId, $salon->salon_adi, number_format($toplam, 2, ',', '.').' TL');

        return ['form_id' => $formId, 'link' => rtrim((string) config('panel.eski_panel_url'), '/').'/odeme?form='.$formId, 'toplam' => $toplam];
    }
}
