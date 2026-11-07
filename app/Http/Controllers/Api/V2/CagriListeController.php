<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\CagriMerkezi\AramaFiltresi;
use App\Domain\CagriMerkezi\CagriMerkeziEylemi;
use App\Domain\CagriMerkezi\ListeDurumu;
use App\Services\CagriMerkezi\AramaListesiServisi;
use App\Services\CagriMerkezi\ListeOlusturmaServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ÇAĞRI MERKEZİ v2 — LİSTE YÖNETİMİ (yönetici). Eski arama_filtre_onizleme + arama_listesi_ekle, cagri_liste_segmentler, cagri_liste_durum,
 * cagri_liste_personel_degistir, cagri_segment_ata + cagri_toplu_aktar (tek uç), cagri_liste_sil,
 * cagri_segment_indir karşılığı.
 *
 *   POST   /cagri-merkezi/listeler/onizleme     {filtre{}, page, per_page, offset?, limit?, idler?}  canlı sayaç + müşteri listesi
 *                                                 (offset+limit: isim sırasında dilim; idler=1: musteri_idler eklenir)
 *   POST   /cagri-merkezi/listeler              {baslik, personel_id, aranacak_tarih?, filtre{}, mod: tumu|aralik|secili, ids[]?, bas?, bit?}
 *   GET    /cagri-merkezi/listeler/{id}/segmentler
 *   PATCH  /cagri-merkezi/listeler/{id}/durum      {durum: 1|2|3}
 *   PATCH  /cagri-merkezi/listeler/{id}/personel   {personel_id: int|null}   null → atamayı geri al
 *   POST   /cagri-merkezi/listeler/{id}/tasi       {kodlar[]: 'bekleyen'|'0'..'9', personel_id, baslik?}
 *   DELETE /cagri-merkezi/listeler/{id}
 *   GET    /cagri-merkezi/listeler/{id}/csv?kod=   text/csv (Excel uyumlu)
 */
class CagriListeController extends CagriMerkeziTabanController
{
    public function onizleme(Request $request, ListeOlusturmaServisi $s)
    {
        $e = $this->erisim($request, CagriMerkeziEylemi::ListeYonet);
        $l = ListeIstegi::istekten($request, 50);
        $v = $request->validate([
            'offset' => ['nullable', 'integer', 'min:0', 'required_with:limit'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:20000', 'required_with:offset'],
        ]);

        return ApiCevap::basarili($s->onizleme($e, AramaFiltresi::diziden((array) $request->input('filtre', [])), $l->sayfa, $l->sayfaBoyu,
            $v['offset'] ?? null, $v['limit'] ?? null, $request->boolean('idler')));
    }

    public function olustur(Request $request, ListeOlusturmaServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::ListeYonet);
        $v = $request->validate([
            'baslik'         => ['required', 'string', 'max:190'],
            'personel_id'    => ['required', 'integer', 'min:1'],
            'aranacak_tarih' => ['nullable', 'date_format:Y-m-d'],
            'filtre'         => ['nullable', 'array'],
            'mod'            => ['required', Rule::in(['tumu', 'aralik', 'secili'])],
            'ids'            => ['required_if:mod,secili', 'array', 'max:20000'],
            'ids.*'          => ['integer', 'min:1'],
            'bas'            => ['required_if:mod,aralik', 'nullable', 'integer', 'min:1'],
            'bit'            => ['required_if:mod,aralik', 'nullable', 'integer', 'gte:bas'],
        ]);
        $sonuc = $s->olustur($e, AramaFiltresi::diziden($v['filtre'] ?? []), $v);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function segmentler(Request $request, int $id, AramaListesiServisi $s)
    {
        return ApiCevap::basarili($s->segmentler($this->erisim($request, CagriMerkeziEylemi::ListeYonet), $id));
    }

    public function durum(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::ListeYonet);
        $v = $request->validate(['durum' => ['required', 'integer', Rule::in(array_column(ListeDurumu::cases(), 'value'))]]);
        $sonuc = $s->durumDegistir($e, $id, ListeDurumu::from((int) $v['durum']));

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function personel(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::ListeYonet);
        $v = $request->validate(['personel_id' => ['nullable', 'integer', 'min:1']]);
        $sonuc = $s->personelDegistir($e, $id, $v['personel_id'] ?? null);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function tasi(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::ListeYonet);
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
        $sonuc = $s->sil($this->yazmaErisimi($request, CagriMerkeziEylemi::ListeYonet), $id);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function csv(Request $request, int $id, AramaListesiServisi $s)
    {
        $e = $this->erisim($request, CagriMerkeziEylemi::ListeYonet);
        $kod = $request->validate(['kod' => ['required', 'string', Rule::in(['bekleyen', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'])]])['kod'];
        $d = $s->csv($e, $id, $kod);

        return response($d['icerik'], 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$d['dosya'].'"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}
