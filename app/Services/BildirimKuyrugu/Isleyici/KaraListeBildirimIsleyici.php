<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Kara listeye alindiniz" bilgilendirmesi; eski MusteriBildirimServisi::karaListeBildirimi. SMS ayari ayar_id=15 (musteri)
 * aciksa WA/SMS + push. Kara liste suzgecinden muaftir. v2 artik bu turu yazmaz; kuyrukta kalan eski satirlar icin.
 */
class KaraListeBildirimIsleyici extends MusteriTabanliIsleyici
{
    public function __construct(private SmsKanali $sms) {}

    protected function musteriIsle(object $kayit, array $payload, object $musteri): void
    {
        $salonId = (int) $kayit->salon_id;

        if (DB::table('salon_sms_ayarlari')->where('ayar_id', 15)->where('salon_id', $salonId)->value('musteri') != 1) {
            return;
        }
        if (! DB::table('musteri_portfoy')->where('user_id', $musteri->id)->where('salon_id', $salonId)->exists()) {
            return;
        }

        $salonTel = DB::table('salonlar')->where('id', $salonId)->value('telefon_1');
        $metin = 'Sayın '.$musteri->name.', isteğiniz doğrultusunda telefon numaranız kara listeye alınmıştır. '
            .'Kampanya ve reklam mesajları gönderilmeyecektir. Detaylı bilgi için bize ulaşınız. 0'.$salonTel;

        $this->sms->panelBildirimli([[
            'to'            => $musteri->cep_telefon,
            'message'       => $metin,
            'user_id'       => $musteri->id,
            'gonderim_tipi' => 'kara_liste_bildirim',
        ]], false, 1, false, $salonId, 'kara_liste_bildirim');

        try {
            PushBildirimi::musteriye((int) $musteri->id, $salonId)
                ->tip(BildirimTipleri::SYSTEM_ANNOUNCEMENT)
                ->baslik('Kara Listeye Alındınız')
                ->govde($metin)
                ->karaListeMuaf(true)
                ->gonder();
        } catch (\Throwable $e) {
            Log::warning('karaliste bildirim push hata: '.$e->getMessage());
        }
    }
}
