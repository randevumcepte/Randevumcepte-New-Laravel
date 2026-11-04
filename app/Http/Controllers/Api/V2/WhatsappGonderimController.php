<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Whatsapp\WhatsappGonderimSorgusu;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Gönderim raporları — salt okuma (eski web + mobil whatsapp/{ozet,loglar,aliciler,alici}).
 *
 *   GET /whatsapp/ozet                      bugun, hafta, ay, basariOrani, gunler[30], numara, gunluk_limit
 *   GET /whatsapp/loglar?durum=&baslangic=&bitis= + ListeIstegi   → {data, sayfalama}
 *   GET /whatsapp/aliciler + ListeIstegi                          → {data, sayfalama}; data[].id = geçmiş anahtarı
 *   GET /whatsapp/aliciler/{id}/gecmis      {telefon, musteri_adi, data[son 100]}
 *
 * Açık telefon `musteri.telefon_gor` yetkisine bağlı (yoksa sunucu maskeler).
 */
class WhatsappGonderimController extends WhatsappTabanController
{
    public function __construct(private WhatsappGonderimSorgusu $sorgu) {}

    public function ozet(Request $request)
    {
        [, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->sorgu->ozet($salonId));
    }

    public function loglar(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $filtre = $request->validate([
            'durum'     => ['nullable', 'integer', 'between:0,3'],
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
            'bitis'     => ['nullable', 'date_format:Y-m-d'],
        ]);
        $l = ListeIstegi::istekten($request, 25, array_keys(WhatsappGonderimSorgusu::LOG_SIRALAMA), 'tarih')->dizi();

        return ApiCevap::basarili($this->sorgu->loglar($salonId, $filtre, $l, $this->telefonGor($uid, $salonId)));
    }

    public function aliciler(Request $request)
    {
        [$uid, $salonId] = $this->salon($request);
        $l = ListeIstegi::istekten($request, 25, array_keys(WhatsappGonderimSorgusu::ALICI_SIRALAMA), 'son')->dizi();

        return ApiCevap::basarili($this->sorgu->aliciler($salonId, $l, $this->telefonGor($uid, $salonId)));
    }

    public function aliciGecmisi(Request $request, int $id)
    {
        [$uid, $salonId] = $this->salon($request);

        return ApiCevap::basarili($this->sorgu->aliciGecmisi($salonId, $id, $this->telefonGor($uid, $salonId)));
    }

    private function telefonGor(int $uid, int $salonId): bool
    {
        return $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }
}
