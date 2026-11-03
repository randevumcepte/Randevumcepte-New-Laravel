<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\CagriMerkezi\AramaRandevuServisi;
use App\Services\CagriMerkezi\CagriKayitlariServisi;
use App\Services\CagriMerkezi\PerformansServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * ÇAĞRI MERKEZİ v2 — takvim, hatırlatma, ajanda, performans ve çağrı kayıtları (salt okuma).
 * Eski arama-randevu-takvim, cagri-yaklasan-randevular, arama-randevularim, arama-dashboard ve
 * arama-cagri-kayitlari uçlarının karşılığı.
 *
 *   GET /cagri-merkezi/takvim?salon_id=&bas=&bit=           {olaylar[], lejand[]}
 *   GET /cagri-merkezi/yaklasan?salon_id=                   hatırlatma popup'ı (faz: on5|zaman|gecikti)
 *   GET /cagri-merkezi/ajanda?salon_id=&tarih=              Arama Randevularım
 *   GET /cagri-merkezi/performans?salon_id=                 (yönetici) personel kartları + özet
 *   GET /cagri-merkezi/performans/{personelId}?salon_id=    (yönetici) görüşme dökümü
 *   GET /cagri-merkezi/cagri-kayitlari?salon_id=&tarih1=&tarih2=&yon=&ara=&page=&per_page=
 */
class CagriRaporController extends CagriMerkeziTabanController
{
    public function takvim(Request $request, AramaRandevuServisi $s)
    {
        $e = $this->erisim($request);
        $v = $request->validate(['bas' => ['nullable', 'date_format:Y-m-d'], 'bit' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bas']]);

        return ApiCevap::basarili($s->takvim($e, $v['bas'] ?? null, $v['bit'] ?? null));
    }

    public function yaklasan(Request $request, AramaRandevuServisi $s)
    {
        return ApiCevap::basarili($s->yaklasanlar($this->erisim($request)));
    }

    public function ajanda(Request $request, AramaRandevuServisi $s)
    {
        $e = $this->erisim($request);
        $v = $request->validate(['tarih' => ['nullable', 'date_format:Y-m-d']]);

        return ApiCevap::basarili($s->ajanda($e, $v['tarih'] ?? null));
    }

    public function performans(Request $request, PerformansServisi $s)
    {
        return ApiCevap::basarili($s->kartlar($this->erisim($request)));
    }

    public function personelDetay(Request $request, int $personelId, PerformansServisi $s)
    {
        return ApiCevap::basarili($s->personelDetay($this->erisim($request), $personelId));
    }

    public function cagriKayitlari(Request $request, CagriKayitlariServisi $s)
    {
        $e = $this->erisim($request);
        $v = $request->validate([
            'tarih1' => ['nullable', 'date_format:Y-m-d'],
            'tarih2' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tarih1'],
            'yon'    => ['nullable', 'in:gelen,giden'],
        ]);

        return ApiCevap::basarili($s->listele($e, [
            'tarih1' => $v['tarih1'] ?? null, 'tarih2' => $v['tarih2'] ?? null, 'yon' => $v['yon'] ?? null,
        ] + ListeIstegi::istekten($request, 25)->dizi()));
    }
}
