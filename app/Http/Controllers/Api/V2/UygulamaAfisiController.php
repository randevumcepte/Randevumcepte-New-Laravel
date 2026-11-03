<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Afis\AfisIcerigi;
use App\Exceptions\GecersizIslemException;
use App\Services\Afis\AfisIcerikServisi;
use App\Services\Afis\AfisSunumu;
use App\Services\Afis\AfisUretici;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * UYGULAMA İNDİRME AFİŞİ v2 (eski /isletmeyonetim/uygulama-afisi[-pdf]) — ince controller, SALT OKUMA.
 *
 *   GET /uygulama-afisi?salon_id=           → durum + link + paylaşım (AfisSunumu)
 *   GET /uygulama-afisi/onizleme?salon_id=  → image/jpeg (afiş, A4 oranında)
 *   GET /uygulama-afisi/pdf?salon_id=       → application/pdf (indirme)
 *
 * Erişim eski ekranla BİREBİR: salonun her aktif üyesi (yalnız kapsam; afiş herkese açık
 * /indir QR'ını gösteren zararsız bir pazarlama çıktısı). Menü görünürlüğü PanelMenu'de
 * (pazarlama.kampanya_yonet). Görsel uçları Android + iOS linki yoksa 422.
 */
class UygulamaAfisiController extends V2Controller
{
    public function __construct(
        private AfisIcerikServisi $icerikler,
        private AfisUretici $uretici,
    ) {}

    public function ozet(Request $request)
    {
        [$icerik, $linkler] = $this->icerikler->getir($this->kapsam($request));

        return ApiCevap::basarili(AfisSunumu::ozet($icerik, $linkler));
    }

    public function onizleme(Request $request): Response
    {
        return response($this->uretici->jpeg($this->hazirIcerik($request)), 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function pdf(Request $request): Response
    {
        $icerik = $this->hazirIcerik($request);

        return response($this->uretici->pdf($icerik), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$icerik->dosyaAdi('pdf').'"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /** Görsel uçları için: kapsam + afiş hazır (Android VE iOS linki) şartı. */
    private function hazirIcerik(Request $request): AfisIcerigi
    {
        [$icerik, $linkler] = $this->icerikler->getir($this->kapsam($request));
        if (! $linkler->afisHazir()) {
            throw new GecersizIslemException('Afiş için App Store ve Google Play bağlantılarının ikisi de tanımlı olmalı');
        }

        return $icerik;
    }

    private function kapsam(Request $request): int
    {
        return $this->salonDenetle($request, [])[1];
    }
}
