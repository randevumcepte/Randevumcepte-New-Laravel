<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\Santral\CagriRaporServisi;
use App\Services\Santral\DakikaServisi;
use App\Services\Santral\SantralAyarServisi;
use App\Services\Santral\SantralErisimServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Santral API v2 controller (ince) — eski "Santral Sistemi & Aramalar" sayfasinin
 * (web) ve santralraporlari ekraninin (Flutter) ORTAK ucu. Kimlik + kapsam + rol
 * SantralErisimServisi'nde; is mantigi Santral servislerinde.
 *
 * Okuma uclari her rolde acik (personel kendi dahilisiyle sinirli); ayar uclari
 * yalniz yonetici. Ayar YAZMA config('santral.yazma_aktif') kill-switch arkasinda.
 */
class SantralController extends V2Controller
{
    protected ?string $yazmaAlani = 'santral';

    public function __construct(
        private SantralErisimServisi $erisim,
        private CagriRaporServisi $rapor,
        private DakikaServisi $dakika,
        private SantralAyarServisi $ayar,
    ) {}

    /** GET /api/v2/santral/ozet?salon_id= — ekran kurgusu (erisim bayraklari) + dakika karti */
    public function ozet(Request $request)
    {
        $salonId = $this->salonId($request);
        $erisim = $this->erisim->coz($this->kullanici()->id, $salonId);

        return ApiCevap::basarili([
            'erisim' => $erisim->dizi(),
            'dakika' => $this->dakika->ozet($salonId, $erisim),
        ]);
    }

    /**
     * GET /api/v2/santral/cagrilar?salon_id=&tarih1=&tarih2=&kategori=&ara=&page=&per_page=
     * kategori: gelen | giden | cevapsiz | ulasilamadi (bos → tumu). Tarih varsayilani bugun.
     */
    public function cagrilar(Request $request)
    {
        $salonId = $this->salonId($request);
        $erisim = $this->erisim->coz($this->kullanici()->id, $salonId);
        [$tarih1, $tarih2] = $this->tarihAraligi($request);
        $liste = ListeIstegi::istekten($request, 25);

        return ApiCevap::basarili($this->rapor->listele($salonId, $erisim, [
            'tarih1'   => $tarih1,
            'tarih2'   => $tarih2,
            'kategori' => $request->input('kategori'),
            'ara'      => $liste->ara,
            'page'     => $liste->sayfa,
            'per_page' => $liste->sayfaBoyu,
        ]));
    }

    /** GET /api/v2/santral/ayarlar?salon_id= — hatirlatma + ozel karsilama menusu (yonetici) */
    public function ayarlar(Request $request)
    {
        $salonId = $this->salonId($request);
        $this->erisim->yoneticiZorunlu($this->kullanici()->id, $salonId);

        return ApiCevap::basarili($this->ayar->getir($salonId));
    }

    /** PUT /api/v2/santral/ayarlar/hatirlatma  body: {salon_id, aktif:bool, saat_once:1-23} */
    public function hatirlatmaKaydet(Request $request)
    {
        $salonId = $this->salonId($request);
        $user = $this->kullanici();
        $this->erisim->yoneticiZorunlu($user->id, $salonId);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->ayar->hatirlatmaKaydet($salonId, $user->id, $request->boolean('aktif'), (int) $request->input('saat_once')),
            'Santral ayarları kaydedildi.'
        );
    }

    /** PUT /api/v2/santral/ayarlar/ozel-menu  body: {salon_id, aktif:bool, secenekler:[{tus,etiket,tip,hedef}]} */
    public function ozelMenuKaydet(Request $request)
    {
        $salonId = $this->salonId($request);
        $user = $this->kullanici();
        $this->erisim->yoneticiZorunlu($user->id, $salonId);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(
            $this->ayar->ozelMenuKaydet($salonId, $user->id, $request->boolean('aktif'), (array) $request->input('secenekler', [])),
            'Özel karşılama menüsü kaydedildi.'
        );
    }

    // ---------------------------------------------------------------------

    /** @return array{0:string,1:string} Y-m-d; bos → bugun, ters sira duzeltilir. */
    private function tarihAraligi(Request $request): array
    {
        $bugun = date('Y-m-d');
        $tarihler = array_map(function ($alan) use ($request, $bugun) {
            $v = trim((string) $request->input($alan, ''));
            if ($v === '') {
                return $bugun;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (! $d || $d->format('Y-m-d') !== $v) {
                throw new GecersizIslemException($alan.' Y-m-d biçiminde olmalı');
            }

            return $v;
        }, ['tarih1', 'tarih2']);
        sort($tarihler);

        return $tarihler;
    }
}
