<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Ders\KatilimciDurumu;
use App\Exceptions\GecersizIslemException;
use App\Services\Ders\DanisanDersServisi;
use App\Services\Musteri\OlcumServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Stüdyo — MUSTERI uygulamasi (eski /grup-dersleri, /grup-dersi-rezervasyon, /danisan-*).
 *
 *   GET  /musteri-uygulama/grup-dersleri?salon_id=                      online rezervasyona acik dersler
 *   POST /musteri-uygulama/grup-dersleri/{oturumId}/rezervasyon {salon_id}
 *   GET  /musteri-uygulama/derslerim?salon_id=                          son 3 gun + ileri
 *   POST /musteri-uygulama/derslerim/{katilimciId}/katilim {durum: geldi|gelmedi}
 *   GET  /musteri-uygulama/olcumlerim?salon_id=                         artan tarih (grafik)
 */
class MusteriDersController extends MusteriUygulamaController
{
    public function __construct(private DanisanDersServisi $servis) {}

    public function uygunDersler(Request $request)
    {
        return ApiCevap::basarili($this->servis->uygunDersler($this->salonZorunlu($request)));
    }

    public function rezervasyon(Request $request, int $oturumId)
    {
        $sonuc = $this->servis->rezervasyon($this->salonZorunlu($request), $oturumId, $this->musteriId(), $request->boolean('mobil'));

        return ApiCevap::basarili($sonuc, $sonuc['mesaj'], 201);
    }

    public function derslerim(Request $request)
    {
        return ApiCevap::basarili($this->servis->dersler($this->musteriId(), $request->integer('salon_id') ?: null));
    }

    public function katilim(Request $request, int $katilimciId)
    {
        $request->validate(['durum' => ['required', 'in:geldi,gelmedi']]);
        $sonuc = $this->servis->katilimBildir($katilimciId, $this->musteriId(), KatilimciDurumu::from($request->input('durum')));

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function olcumlerim(Request $request, OlcumServisi $olcum)
    {
        return ApiCevap::basarili($olcum->danisanListe($this->musteriId(), $request->integer('salon_id') ?: null));
    }

    private function salonZorunlu(Request $request): int
    {
        return $request->integer('salon_id') ?: throw new GecersizIslemException('salon_id zorunlu');
    }
}
