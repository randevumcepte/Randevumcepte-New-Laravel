<?php

namespace App\Services\Kampanya;

use App\Support\BildirimKuyrugu;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;

/**
 * Kampanya ve etkinlik katılımcılarına SMS: metin + (ayar 10 açıksa) katılım linki, kara liste hariç.
 * Eski etkinlikekleduzenle, etkinliktekrarsmsgonder ve kampanyatekrarsmsgonder ile aynı metin.
 */
class KatilimSmsServisi
{
    private const KATILIM_LINKI_AYARI = 10;

    /**
     * @param  iterable<object{user_id:int, cep_telefon:?string}>  $katilimcilar
     * @param  string  $linkYolu  'etkinlikkatilim/12' gibi; sonuna kullanıcı eklenir
     * @return int kuyruğa yazılan mesaj
     */
    public function gonder(int $salonId, int $yetkiliId, iterable $katilimcilar, string $metin, string $linkYolu): int
    {
        $linkAcik = (int) DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)
            ->where('ayar_id', self::KATILIM_LINKI_AYARI)->value('musteri') === 1;
        $karaListe = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('kara_liste', 1)->pluck('user_id')->flip();

        $mesajlar = [];
        foreach ($katilimcilar as $k) {
            if (! isset($karaListe[$k->user_id])) {
                $link = $linkAcik ? ' Katılım için : '.EskiPanelLinki::url($linkYolu.'/'.$k->user_id) : '';
                $mesajlar[] = ['to' => $k->cep_telefon, 'message' => $metin.$link];
            }
        }

        // Kampanya katilimcilarina toplu duyuru: WhatsApp toplu gonderime uygun degil, yalniz SMS.
        return BildirimKuyrugu::sms($salonId, $yetkiliId, $mesajlar, false, true);
    }
}
