<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Cark\PuanOdulTipi;
use App\Http\Requests\Sadakat\KazanimKuraliIstegi;
use App\Http\Requests\Sadakat\PuanOdulIstegi;
use App\Services\Sadakat\PuanOdulServisi;
use App\Services\Sadakat\SadakatKazanimServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\SubeGrubu;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * SADAKAT PUANI v2 (eski /isletmeyonetim/puanodulleri + puanodulkaydet/sil/orneklerekle +
 * puankazanimkaydet; mobil carkAdmin/puan-odul*, puan-kazanim) — Çarkıfelek sayfasının
 * "Puan Ödülleri" sekmesi.
 *
 *   GET    /sadakat?salon_id=                 {odul_seviyeleri[], kazanim{}, sozluk{odul_tipleri}, subeler[]}
 *   POST   /sadakat/puan-odulu                yeni basamak (+ salon_ids çoklu şube)
 *   PATCH  /sadakat/puan-odulu/{id}           düzenle (grup yalnız salon_ids gelirse değişir)
 *   DELETE /sadakat/puan-odulu/{id}
 *   POST   /sadakat/puan-odulu/ornekler       merdiven boşken 6 örnek
 *   PUT    /sadakat/kazanim                   her X TL'ye Y puan + randevuya Z puan
 *
 * Yetki: pazarlama.cark_yonet. Yazmalar CARK_V2_YAZMA_AKTIF arkasında.
 */
class SadakatController extends V2Controller
{
    protected ?string $yazmaAlani = 'cark';

    protected string $yazmaKapaliMesaj = 'Sadakat v2 yazma şu an kapalı';

    public function __construct(
        private PuanOdulServisi $oduller,
        private SubeGrubu $subeler,
    ) {}

    public function goster(Request $request, SadakatKazanimServisi $kazanim)
    {
        [$uid, $salonId] = $this->salonDenetle($request, CarkController::YONET);

        return ApiCevap::basarili([
            'odul_seviyeleri' => $this->oduller->liste($salonId),
            'kazanim'         => $kazanim->getir($salonId),
            'sozluk'          => ['odul_tipleri' => PuanOdulTipi::sozluk()],
            'subeler'         => $this->subeler->secenekler($uid, $salonId),
        ]);
    }

    public function odulEkle(PuanOdulIstegi $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, CarkController::YONET);
        $this->yazmaAcikMi();
        $hedefler = $this->subeler->hedefler($uid, $salonId, $request->input('salon_ids'), CarkController::YONET);

        return ApiCevap::basarili(['id' => $this->oduller->ekle($salonId, $uid, $request->validated(), $hedefler), 'subeler' => $hedefler,
            'odul_seviyeleri' => $this->oduller->liste($salonId)], 'Puan ödülü eklendi', 201);
    }

    public function odulGuncelle(PuanOdulIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->sadakatKaydi($id);
        $this->yazmaAcikMi();
        $hedefler = $request->filled('salon_ids') ? $this->subeler->hedefler($uid, $salonId, $request->input('salon_ids'), CarkController::YONET) : null;
        $this->oduller->guncelle($salonId, $uid, $id, $request->validated(), $hedefler);

        return ApiCevap::basarili(['odul_seviyeleri' => $this->oduller->liste($salonId)], 'Puan ödülü güncellendi');
    }

    public function odulSil(int $id)
    {
        [$uid, $salonId] = $this->sadakatKaydi($id);
        $this->yazmaAcikMi();
        $this->oduller->sil($salonId, $uid, $id);

        return ApiCevap::basarili(['odul_seviyeleri' => $this->oduller->liste($salonId)], 'Puan ödülü silindi');
    }

    public function ornekler(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, CarkController::YONET);
        $this->yazmaAcikMi();
        $sayi = $this->oduller->ornekleriEkle($salonId, $uid);

        return ApiCevap::basarili(['eklenen' => $sayi, 'odul_seviyeleri' => $this->oduller->liste($salonId)], "{$sayi} örnek ödül eklendi", 201);
    }

    public function kazanimKaydet(KazanimKuraliIstegi $request, SadakatKazanimServisi $kazanim)
    {
        [$uid, $salonId] = $this->salonDenetle($request, CarkController::YONET);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($kazanim->kaydet($salonId, $uid, $request->validated()), 'Puan kazanma kuralı kaydedildi');
    }

    /** Yol parametresindeki ödülün salonu (yoksa 404) → kapsam + yetki o salonda. */
    private function sadakatKaydi(int $id): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->oduller->salonId($id), 'Puan ödülü bulunamadı');
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, CarkController::YONET);

        return [$uid, $salonId];
    }
}
