<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\CagriMerkezi\AramaListesiServisi;
use App\Services\CagriMerkezi\CagriReferansServisi;
use App\Services\CagriMerkezi\GorusmeGecmisiServisi;
use App\Services\CagriMerkezi\KuyrukServisi;
use App\Services\Randevu\RandevuFormServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * ÇAĞRI MERKEZİ v2 — okuma uçları (Çalışma Ekranı). Eski arama-listelerim sayfası + v1
 * /api/v1/cagri-merkezi okuma uçlarının karşılığı; web (/v2/cagri-merkezi) ve Flutter AYNI uçlar.
 *
 *   GET /cagri-merkezi/acilis?salon_id=                  erişim, sekmeler, sözlükler, script, kategori, personel
 *   GET /cagri-merkezi/listeler?salon_id=&arsiv=&tumu=    liste kartları (tumu: yönetici, kuyruğu boşlar dahil)
 *   GET /cagri-merkezi/listeler/{id}/musteriler?grup=&ara=&page=&per_page=   kuyruk + durum çipleri
 *   GET /cagri-merkezi/kayit/{id}?salon_id=               tek aranacak müşteri (kuyruk satırı biçimi)
 *   GET /cagri-merkezi/kayit/{id}/gecmis?salon_id=        çağrı geçmişi + ses kayıtları
 *   GET /cagri-merkezi/kayit/{id}/ses-kayitlari?salon_id= müşteriyle TÜM ses kayıtları (son 120 gün)
 *   GET /cagri-merkezi/kayit/{id}/musteri?salon_id=       dönüşüm formu müşteri kimliği (ad tam, telefon yetkiye göre)
 *   GET /cagri-merkezi/musteri-ara?salon_id=&q=           manuel arama randevusu için müşteri arama
 */
class CagriMerkeziController extends CagriMerkeziTabanController
{
    public function acilis(Request $request, CagriReferansServisi $s)
    {
        return ApiCevap::basarili($s->acilis($this->erisim($request)));
    }

    public function listeler(Request $request, AramaListesiServisi $s)
    {
        return ApiCevap::basarili($s->kartlar($this->erisim($request),
            filter_var($request->input('arsiv'), FILTER_VALIDATE_BOOLEAN), filter_var($request->input('tumu'), FILTER_VALIDATE_BOOLEAN)));
    }

    public function kuyruk(Request $request, int $id, KuyrukServisi $s)
    {
        $e = $this->erisim($request);
        $grup = $request->validate(['grup' => ['nullable', 'string', 'max:30']])['grup'] ?? null;

        return ApiCevap::basarili($s->listele($e, $id, $grup, ListeIstegi::istekten($request, 100)->dizi()));
    }

    public function kayit(Request $request, int $id, KuyrukServisi $s)
    {
        return ApiCevap::basarili($s->tekil($this->erisim($request), $id));
    }

    public function musteri(Request $request, int $id, KuyrukServisi $s)
    {
        return ApiCevap::basarili($s->musteriBilgisi($this->erisim($request), $id));
    }

    public function gecmis(Request $request, int $id, GorusmeGecmisiServisi $s)
    {
        return ApiCevap::basarili($s->getir($this->erisim($request), $id));
    }

    public function sesKayitlari(Request $request, int $id, GorusmeGecmisiServisi $s)
    {
        return ApiCevap::basarili($s->tumSesler($this->erisim($request), $id));
    }

    /** Arama randevusu ekleme için müşteri arama (randevu formuyla AYNI kural; telefon maskesi). */
    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        $e = $this->erisim($request);

        return ApiCevap::basarili($form->musteriAra($e->salonId, (string) $request->input('q', ''), ! $e->maskele()));
    }
}
