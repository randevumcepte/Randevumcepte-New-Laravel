<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Whatsapp\BaglantiDurumu;
use App\Domain\Whatsapp\GonderimDurumu;
use App\Domain\Whatsapp\WhatsappSayfasi;
use App\Http\Requests\Whatsapp\WhatsappLinkIstegi;
use App\Services\Whatsapp\WhatsappAyarServisi;
use App\Services\Whatsapp\WhatsappKontorServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * WHATSAPP YÖNETİMİ v2 — sayfa açılışı + salon ayarları (eski /isletmeyonetim/whatsapp;
 * mobil whatsapp/kanal-*, whatsapp-ayar-kaydet).
 *
 *   GET /whatsapp                    sekmeler, sözlükler, kanal, linkler, kontör (tek istek)
 *   GET /whatsapp/kanal · PUT {aktif} hatırlatma kanalı
 *   GET /whatsapp/linkler · PUT       mesaja eklenen işletme bağlantıları (kısmi kayıt)
 *
 * Eşleme: docs/strangler-whatsapp.md
 */
class WhatsappController extends WhatsappTabanController
{
    public function __construct(private WhatsappAyarServisi $ayar) {}

    public function acilis(Request $request, WhatsappKontorServisi $kontor)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili([
            'sekmeler'      => WhatsappSayfasi::sekmeler(),
            'nasil_calisir' => WhatsappSayfasi::nasilCalisir(),
            'sozluk'        => [
                'baglanti_durumlari' => BaglantiDurumu::sozluk(),
                'gonderim_durumlari' => GonderimDurumu::sozluk(),
            ],
            'kanal'     => $this->ayar->kanal($salonId),
            'linkler'   => $this->ayar->linkler($salonId),
            'kontor'    => $kontor->durum($salonId),
            'yazabilir' => (bool) config('whatsapp.yazma_aktif'),
        ]);
    }

    public function kanal(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->ayar->kanal($salonId));
    }

    public function kanalDegistir(Request $request)
    {
        [$uid, $salonId] = $this->yazilacakSalon($request);
        $v = $request->validate(['aktif' => ['required', 'boolean']]);

        return ApiCevap::basarili($this->ayar->kanalDegistir($salonId, $uid, (bool) $v['aktif']), 'Ayar kaydedildi');
    }

    public function linkler(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->ayar->linkler($salonId));
    }

    public function linkKaydet(WhatsappLinkIstegi $request)
    {
        [$uid, $salonId] = $this->yazilacakSalon($request);

        return ApiCevap::basarili($this->ayar->linkKaydet($salonId, $uid, $request->validated()), 'Bağlantılar kaydedildi');
    }
}
