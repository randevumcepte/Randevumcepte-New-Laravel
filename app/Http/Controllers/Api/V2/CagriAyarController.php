<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\CagriMerkezi\CagriMerkeziEylemi;
use App\Services\CagriMerkezi\CagriAyarServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * ÇAĞRI MERKEZİ v2 — AYARLAR (yönetici): görüşme scriptleri + sonuç kategori ağacı.
 * Eski /isletmeyonetim/cagri-ayarlari + cagri_script_* / cagri_kategori_* karşılığı.
 *
 *   GET    /cagri-merkezi/ayarlar                    {scriptler[] (pasifler dahil), kategoriler[] (ağaç)}
 *   POST   /cagri-merkezi/scriptler                  {baslik, icerik?, aktif?}
 *   PATCH  /cagri-merkezi/scriptler/{id}             {baslik, icerik?, aktif?}
 *   DELETE /cagri-merkezi/scriptler/{id}
 *   POST   /cagri-merkezi/kategoriler                {ad, ust_id?}   ust_id → alt kategori
 *   PATCH  /cagri-merkezi/kategoriler/{id}           {ad}
 *   DELETE /cagri-merkezi/kategoriler/{id}           (ana kategori → alt kategorileriyle)
 */
class CagriAyarController extends CagriMerkeziTabanController
{
    public function getir(Request $request, CagriAyarServisi $s)
    {
        return ApiCevap::basarili($s->getir($this->erisim($request, CagriMerkeziEylemi::AyarYonet)));
    }

    public function scriptKaydet(Request $request, CagriAyarServisi $s, ?int $id = null)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::AyarYonet);
        $v = $request->validate(['baslik' => ['required', 'string', 'max:190'], 'icerik' => ['nullable', 'string', 'max:20000'], 'aktif' => ['nullable', 'boolean']]);

        return ApiCevap::basarili($s->scriptKaydet($e, $id, $v), 'Script kaydedildi.');
    }

    public function scriptSil(Request $request, int $id, CagriAyarServisi $s)
    {
        return ApiCevap::basarili($s->scriptSil($this->yazmaErisimi($request, CagriMerkeziEylemi::AyarYonet), $id), 'Script silindi.');
    }

    public function kategoriKaydet(Request $request, CagriAyarServisi $s, ?int $id = null)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::AyarYonet);
        $v = $request->validate(['ad' => ['required', 'string', 'max:190'], 'ust_id' => ['nullable', 'integer', 'min:1']]);

        return ApiCevap::basarili($s->kategoriKaydet($e, $id, $v['ad'], $v['ust_id'] ?? null), 'Kategori kaydedildi.');
    }

    public function kategoriSil(Request $request, int $id, CagriAyarServisi $s)
    {
        return ApiCevap::basarili($s->kategoriSil($this->yazmaErisimi($request, CagriMerkeziEylemi::AyarYonet), $id), 'Kategori silindi.');
    }
}
