<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\IsletmeYetkilileri;
use App\Models\Randevular;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use App\Services\BildirimKuyrugu\Kanal\UygulamaIciBildirim;
use App\Services\BildirimKuyrugu\Randevu\RandevuPushu;
use App\Services\BildirimKuyrugu\Randevu\RandevuVerisi;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ongorusme_kayit: eski V2BildirimIsle ongorusme dali + OnGorusmeBildirimServisi::kayitBildirimi. Ayar ayar_id=12; ayar satiri yoksa
 * hic mesaj gitmez. Musteri ve personel mesaji panel yolu (WA-first + SMS, kara liste). SMS hatasi yutulmaz, kayit yeniden denenir.
 */
class OngorusmeKayitIsleyici extends RandevuTabanliIsleyici
{
    private const AYAR_ID = 12;

    public function __construct(
        private RandevuVerisi $veri,
        private SmsKanali $sms,
        private UygulamaIciBildirim $bildirim,
    ) {}

    protected function randevuIsle(object $kayit, array $payload, Randevular $randevu): void
    {
        $og = DB::table('on_gorusmeler')->where('id', (int) ($payload['on_gorusme_id'] ?? $randevu->on_gorusme_id))->first();
        if (! $og) {
            throw new RuntimeException('On gorusme bulunamadi: '.($payload['on_gorusme_id'] ?? '-'));
        }

        $ayar = DB::table('salon_sms_ayarlari')->where('ayar_id', self::AYAR_ID)->where('salon_id', $og->salon_id)->first(['musteri', 'personel']);
        if (! $ayar) {
            return;
        }
        $salon = $this->veri->salon($og->salon_id);
        $zaman = date('d.m.Y', strtotime($randevu->tarih)).' - '.date('H:i', strtotime($randevu->saat));

        $telefon = $og->cep_telefon ?: DB::table('users')->where('id', $og->user_id)->value('cep_telefon');
        if ($ayar->musteri == 1 && $telefon && $salon) {
            $this->sms->panelBildirimli([[
                'to' => $telefon,
                'message' => $salon->salon_adi.' tarafından '.$zaman.' olarak ön görüşme randevunuz düzenlenmiştir. '
                    .'Randevunuza 15 dk önce gelmenizi rica ederiz. Detaylı bilgi için bize ulaşın. 0'.$salon->telefon_1,
            ]], false, 1, false, $og->salon_id);
        }

        if ($ayar->personel != 1 || ! $og->personel_id) {
            return;
        }
        $yetkili = $kayit->yetkili_id ? IsletmeYetkilileri::find($kayit->yetkili_id) : null;
        $mesaj = $og->ad_soyad.' isimli müşterinin '.$zaman.' '.$this->nedenAdi($og).' için ön görüşme randevusu '
            .($yetkili?->name ?: 'işletme').' tarafından düzenlenmiştir.';

        $personelGsm = $this->veri->personelGsm($og->personel_id);
        if ($personelGsm) {
            $this->sms->panelBildirimli([['to' => $personelGsm, 'message' => $mesaj]], false, 1, false, $og->salon_id);
        }
        $this->bildirim->ekle($og->salon_id, $mesaj, '#', $og->personel_id, null, $yetkili?->profil_resim, $randevu->id);

        RandevuPushu::yolla(PushBildirimi::personele((int) $og->personel_id, (int) $og->salon_id), BildirimTipleri::APPOINTMENT_CREATED,
            'Yeni Ön Görüşme Randevusu', $mesaj, $randevu->id, 'on gorusme push', ['ongorusme' => '1'], ['on_gorusme' => 1]);
    }

    private function nedenAdi(object $og): string
    {
        if ($og->paket_id) {
            return DB::table('paketler')->where('id', $og->paket_id)->value('paket_adi') ?: 'Paket';
        }
        if ($og->urun_id) {
            return DB::table('urunler')->where('id', $og->urun_id)->value('urun_adi') ?: 'Ürün';
        }
        if ($og->hizmet_id) {
            return DB::table('hizmetler')->where('id', $og->hizmet_id)->value('hizmet_adi') ?: 'Hizmet';
        }

        return 'Ön Görüşme';
    }
}
