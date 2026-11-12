<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\CagriMerkezi\CagriMerkeziEylemi;
use App\Services\CagriMerkezi\AnketGonderimServisi;
use App\Services\CagriMerkezi\AramaBaslatmaServisi;
use App\Services\CagriMerkezi\GorusmeSonucServisi;
use App\Services\CagriMerkezi\TelefondaSatisServisi;
use App\Services\CagriMerkezi\WhatsappMesajServisi;
use App\Services\Satis\TekKalemSatisServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ÇAĞRI MERKEZİ v2 — aranacak müşteri üzerindeki İŞLEMLER (eski santral_not_ekle, arama_baslat,
 * cagri_randevu_verildi_isaretle, cagri_arama_randevu_iptal, arama_randevu_ertele,
 * cagri_arama_randevu_ekle, cagri_hizli_satis, cagri_anket_gonder).
 *
 *   POST /cagri-merkezi/kayit/{id}/ara  {softphone?}   santral originate (numara istemciye gitmez); softphone=1 ve
 *                                                      numarayı görebilen kullanıcı → {softphone:true, numara_ara}
 *   POST /cagri-merkezi/ara            {user_id?|numara?, softphone?}  müşteri kartı / çağrı kaydı "Ara"
 *   POST /cagri-merkezi/kayit/{id}/sonuc               {sonuc?, not?, tarih?, saat?, satis_tutari?, kategori_id?, alt_kategori_id?, arama_personel_id?}
 *   POST /cagri-merkezi/kayit/{id}/randevu-verildi     {yalniz_bekleyen?} normal randevu oluşturuldu → 8 + rozet
 *   POST /cagri-merkezi/kayit/{id}/iptal               arama randevusu iptal (9)
 *   POST /cagri-merkezi/kayit/{id}/ertele              {tarih, saat}
 *   POST /cagri-merkezi/kayit/{id}/satis               {tur, katalog_id, fiyat?, seans?, adet?, personel_id?, not?, idempotency_key} → {adisyon_id}
 *   POST /cagri-merkezi/kayit/{id}/anket               memnuniyet anketi (outbox; web ve mobil Anket eylem yetkisi) → {mesaj, beklenen_kanal}
 *   POST /cagri-merkezi/kayit/{id}/whatsapp {mesaj}    manuel WhatsApp mesajı (outbox; bağlantı ön-kontrolü 409)
 *   POST /cagri-merkezi/arama-randevusu                {musteri_id, personel_id, tarih, saat, not?}
 *
 * Arama başlatma yazma bayrağından bağımsızdır; diğerleri CAGRI_MERKEZI_V2_YAZMA_AKTIF arkasında.
 */
class CagriIslemController extends CagriMerkeziTabanController
{
    public function ara(Request $request, int $id, AramaBaslatmaServisi $s)
    {
        $sonuc = $s->kayittan($this->erisim($request, CagriMerkeziEylemi::Arama), $id, filter_var($request->input('softphone'), FILTER_VALIDATE_BOOLEAN));

        return $sonuc['basarili'] ? ApiCevap::basarili($sonuc, $sonuc['mesaj']) : ApiCevap::hata($sonuc['mesaj'], 422, $sonuc);
    }

    public function araNumara(Request $request, AramaBaslatmaServisi $s)
    {
        $v = $request->validate(['user_id' => ['nullable', 'integer', 'min:1'], 'numara' => ['nullable', 'string', 'max:30']]);
        $sonuc = $s->numaradan($this->erisim($request, CagriMerkeziEylemi::Arama), $v['user_id'] ?? null, $v['numara'] ?? null, filter_var($request->input('softphone'), FILTER_VALIDATE_BOOLEAN));

        return $sonuc['basarili'] ? ApiCevap::basarili($sonuc, $sonuc['mesaj']) : ApiCevap::hata($sonuc['mesaj'], 422, $sonuc);
    }

    public function sonuc(Request $request, int $id, GorusmeSonucServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::Calisma);
        $v = $request->validate([
            'sonuc'             => ['nullable', 'integer', 'between:0,9'],
            'not'               => ['nullable', 'string', 'max:5000'],
            'satis_tutari'      => ['nullable', 'numeric', 'min:0'],
            'kategori_id'       => ['nullable', 'integer', 'min:1'],
            'alt_kategori_id'   => ['nullable', 'integer', 'min:1'],
            'arama_personel_id' => ['nullable', 'integer', 'min:1'],
        ] + self::zamanKurallari(false));

        return ApiCevap::basarili($s->kaydet($e, $id, $v), 'Görüşme sonucu kaydedildi.');
    }

    public function randevuVerildi(Request $request, int $id, GorusmeSonucServisi $s)
    {
        return ApiCevap::basarili($s->randevuVerildi($this->yazmaErisimi($request, CagriMerkeziEylemi::Calisma), $id, $request->boolean('yalniz_bekleyen')), 'Randevu oluşturuldu (müşteri listede kaldı).');
    }

    public function iptal(Request $request, int $id, GorusmeSonucServisi $s)
    {
        return ApiCevap::basarili($s->iptal($this->yazmaErisimi($request, CagriMerkeziEylemi::AramaRandevu), $id), 'Arama randevusu iptal edildi.');
    }

    public function ertele(Request $request, int $id, GorusmeSonucServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::AramaRandevu);
        $v = $request->validate(self::zamanKurallari(true));

        return ApiCevap::basarili($s->ertele($e, $id, $v['tarih'], $v['saat']), 'Arama randevusu ertelendi.');
    }

    public function aramaRandevusu(Request $request, GorusmeSonucServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::AramaRandevu);
        $v = $request->validate([
            'musteri_id'  => ['required', 'integer', 'min:1'],
            'personel_id' => ['required', 'integer', 'min:1'],
            'not'         => ['nullable', 'string', 'max:2000'],
        ] + self::zamanKurallari(true));
        $sonuc = $s->manuelEkle($e, (int) $v['musteri_id'], (int) $v['personel_id'], $v['tarih'], $v['saat'], $v['not'] ?? null);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function satis(Request $request, int $id, TelefondaSatisServisi $s)
    {
        $e = $this->yazmaErisimi($request);
        $v = $request->validate([
            'tur'             => ['required', Rule::in(TekKalemSatisServisi::TURLER)],
            'katalog_id'      => ['required', 'integer', 'min:1'],
            'fiyat'           => ['nullable', 'numeric', 'min:0'],
            'seans'           => ['nullable', 'integer', 'min:0', 'max:1000'],
            'adet'            => ['nullable', 'integer', 'min:1', 'max:1000'],
            'personel_id'     => ['nullable', 'integer', 'min:1'],
            'not'             => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        return ApiCevap::basarili($s->sat($e, $id, $v), 'Satış oluşturuldu; tahsilatı satış ekranından alabilirsiniz.');
    }

    public function whatsapp(Request $request, int $id, WhatsappMesajServisi $s)
    {
        $e = $this->yazmaErisimi($request, CagriMerkeziEylemi::Whatsapp);
        $v = $request->validate(['mesaj' => ['required', 'string', 'max:'.WhatsappMesajServisi::MAKS_UZUNLUK]]);
        $sonuc = $s->gonder($e, $id, $v['mesaj']);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function anket(Request $request, int $id, AnketGonderimServisi $s)
    {
        $sonuc = $s->gonder($this->yazmaErisimi($request), $id);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }
}
