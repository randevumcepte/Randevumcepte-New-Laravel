<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\CagriMerkezi\ListeDurumu;
use App\Services\CagriMerkezi\AramaListesiServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ÇAĞRI MERKEZİ v2 — LİSTE YÖNETİMİ (yönetici). Eski cagri_liste_segmentler, cagri_liste_durum,
 * cagri_liste_personel_degistir, cagri_segment_ata + cagri_toplu_aktar (tek uç), cagri_liste_sil,
 * cagri_segment_indir karşılığı.
 *
 *   GET    /cagri-merkezi/listeler/{id}/segmentler
 *   PATCH  /cagri-merkezi/listeler/{id}/durum      {durum: 1|2|3}
 *   PATCH  /cagri-merkezi/listeler/{id}/personel   {personel_id: int|null}   null → atamayı geri al
 *   POST   /cagri-merkezi/listeler/{id}/tasi       {kodlar[]: 'bekleyen'|'0'..'9', personel_id, baslik?}
 *   DELETE /cagri-merkezi/listeler/{id}
 *   GET    /cagri-merkezi/listeler/{id}/csv?kod=   text/csv (Excel uyumlu)
 */
class CagriListeController extends CagriMerkeziTabanController
{
    public function segmentler(Request $request, int $id, AramaListesiServisi $s)
    {
        return ApiCevap::basarili($s->segmentler($this->erisim($request), $id));
    }

    public function durum(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->yazmaErisimi($request);
        $v = $request->validate(['durum' => ['required', 'integer', Rule::in(array_column(ListeDurumu::cases(), 'value'))]]);
        $sonuc = $s->durumDegistir($e, $id, ListeDurumu::from((int) $v['durum']));

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function personel(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->yazmaErisimi($request);
        $v = $request->validate(['personel_id' => ['nullable', 'integer', 'min:1']]);
        $sonuc = $s->personelDegistir($e, $id, $v['personel_id'] ?? null);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function tasi(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->yazmaErisimi($request);
        $v = $request->validate([
            'kodlar'      => ['nullable', 'array', 'max:12'],
            'kodlar.*'    => ['string', Rule::in(['bekleyen', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'])],
            'personel_id' => ['required', 'integer', 'min:1'],
            'baslik'      => ['nullable', 'string', 'max:190'],
        ]);
        $sonuc = $s->tasi($e, $id, $v['kodlar'] ?? [], (int) $v['personel_id'], $v['baslik'] ?? null);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function sil(Request $request, int $id, AramaListesiServisi $s)
    {
        $sonuc = $s->sil($this->yazmaErisimi($request), $id);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function csv(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->erisim($request);
        $kod = $request->validate(['kod' => ['required', 'string', Rule::in(['bekleyen', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'])]])['kod'];
        $d = $s->csv($e, $id, $kod);

        return response($d['icerik'], 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$d['dosya'].'"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}
