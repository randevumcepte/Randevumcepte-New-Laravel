<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Requests\Ders\SablonIstegi;
use App\Http\Requests\Ders\YayinlaIstegi;
use App\Models\DersProgramiSablonu;
use App\Services\Ders\DersProgramServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Haftalik ders programi (eski /ders-programi-liste, /ders-sablon-*, /ders-programi-yayinla).
 *
 *   GET    /ders/program?salon_id=     personeller + hizmetler + sablon + eslesme
 *   POST   /ders/sablon {salon_id, hafta_gunu, saat, saat_bitis, kapasite, ders_tipi?, personel_id?, hizmet_id?, renk?}
 *   PATCH  /ders/sablon/{id}           → {sablon_id, senkron}
 *   DELETE /ders/sablon/{id}           → {temizlenen, korunan}
 *   POST   /ders/yayinla {salon_id, baslangic?, hafta?, bitis?}  → {olusan, atlanan, hafta}
 */
class DersProgramController extends DersTabanController
{
    public function __construct(private DersProgramServisi $servis) {}

    public function program(Request $request)
    {
        [, $salonId] = $this->dersSalonu($request, self::OKU);

        return ApiCevap::basarili($this->servis->program($salonId));
    }

    public function sablonEkle(SablonIstegi $request)
    {
        [$uid, $salonId] = $this->dersSalonu($request, self::YAZ);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->sablonKaydet($salonId, null, $request->validated(), $uid), 'Ders programa eklendi.', 201);
    }

    public function sablonGuncelle(SablonIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->sablonSalonu($id, self::YAZ);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->sablonKaydet($salonId, $id, $request->validated(), $uid, ! $this->mobilYolu()), 'Ders güncellendi.');
    }

    public function sablonSil(int $id)
    {
        [$uid, $salonId] = $this->sablonSalonu($id, self::SIL);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->sablonSil($salonId, $id, $uid), 'Ders programdan silindi.');
    }

    public function yayinla(YayinlaIstegi $request)
    {
        [$uid, $salonId] = $this->dersSalonu($request, self::YAZ);
        $this->yazmaAcikMi();
        $g = $request->validated();

        return ApiCevap::basarili($this->servis->yayinla($salonId, $g['baslangic'] ?? date('Y-m-d'), $g['bitis'] ?? null, $g['hafta'] ?? null, $uid, ! $this->mobilYolu()));
    }

    /** @return array{0:int,1:int} */
    private function sablonSalonu(int $id, string $yetki): array
    {
        return $this->kayitSalonu(DersProgramiSablonu::whereKey($id)->value('salon_id'), $yetki, 'Kayıt bulunamadı');
    }
}
