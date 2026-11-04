<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Anket\GonderimFiltresi;
use App\Domain\Anket\ReputasyonEsikleri;
use App\Domain\Rapor\RaporDonemi;
use App\Services\Anket\AnketAyarServisi;
use App\Services\Anket\AnketFormServisi;
use App\Services\Anket\AnketGonderimSorgusu;
use App\Services\Anket\AnketGonderServisi;
use App\Services\Pazarlama\AnketOzetServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MEMNUNİYET ANKETİ v2 — açılış verisi, özet, gönderimler. Web (/v2/memnuniyet-anketi) ve Flutter AYNI uçlar.
 *
 *   GET   /anket/form-verisi?salon_id=
 *   GET   /anket/ozet?salon_id=&donem=&baslangic=&bitis=
 *   GET   /anket/gonderimler?salon_id=&filtre=&donem=&baslangic=&bitis= + ListeIstegi
 *   GET   /anket/gonderimler/{id}             {gonderim, sorular, cevaplar, sablon_ad, musteri_ad}
 *   PATCH /anket/gonderimler/{id}/web-gizle {gizle}
 *   POST  /anket/hizli-gonder {salon_id, user_id}  (outbox → WhatsApp-first / SMS)
 *
 * Yetki: pazarlama.anket_yonet; açık telefon musteri.telefon_gor. Yazmalar ANKET_V2_YAZMA_AKTIF arkasında.
 */
class AnketController extends AnketTabanController
{
    public function __construct(private AnketGonderimSorgusu $gonderimler) {}

    public function formVerisi(Request $request, AnketFormServisi $form)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);

        return ApiCevap::basarili($form->formVerisi($salonId, [
            'yaz'         => (bool) config('anket.yazma_aktif'),
            'telefon_gor' => $this->telGor($uid, $salonId),
        ]));
    }

    public function ozet(Request $request, AnketOzetServisi $ozet, AnketAyarServisi $ayar)
    {
        [, $salonId] = $this->salonDenetle($request, self::YETKI);
        $donem = RaporDonemi::coz($request->input('donem'), $request->input('baslangic'), $request->input('bitis'), AnketFormServisi::VARSAYILAN_DONEM);

        return ApiCevap::basarili($ozet->yonetim($salonId, $donem, $ayar->satir($salonId)));
    }

    public function gonderimListesi(Request $request, AnketAyarServisi $ayar)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $liste = ListeIstegi::istekten($request, 25, array_keys(AnketGonderimSorgusu::SIRALAMA), 'id', 'desc')->dizi();
        // Dönem gönderilmezse tümü (mobil v1 listesi gibi).
        $donem = $request->filled('donem') ? RaporDonemi::coz($request->input('donem'), $request->input('baslangic'), $request->input('bitis')) : null;

        return ApiCevap::basarili($this->gonderimler->listele(
            $salonId, $liste, GonderimFiltresi::dbden($request->input('filtre')), $donem,
            ReputasyonEsikleri::satirdan($ayar->satir($salonId)), $this->telGor($uid, $salonId),
        ));
    }

    public function gonderimDetay(int $id)
    {
        [$uid, $salonId] = $this->kayitDenetle($this->gonderimler->salonId($id), 'Anket gönderimi bulunamadı');

        return ApiCevap::basarili($this->gonderimler->detay($id, $this->telGor($uid, $salonId)));
    }

    /** Olumlu yorumu salonun web sitesinde göster / gizle (mini site web_gizle'ye bakar). */
    public function webGizle(Request $request, int $id)
    {
        [$uid, $salonId] = $this->kayitDenetle($this->gonderimler->salonId($id), 'Anket gönderimi bulunamadı');
        $this->yazmaAcikMi();
        $gizle = $request->validate(['gizle' => ['required', 'boolean']])['gizle'];
        DB::table('anket_gonderimleri')->where('id', $id)->update(['web_gizle' => $gizle ? 1 : 0, 'updated_at' => now()]);

        return ApiCevap::basarili($this->gonderimler->getir($id, $this->telGor($uid, $salonId)));
    }

    public function hizliGonder(Request $request, AnketGonderServisi $gonderici)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $this->yazmaAcikMi();
        $userId = (int) $request->validate(['user_id' => ['required', 'integer', 'min:1']])['user_id'];

        return ApiCevap::basarili($gonderici->hizli($salonId, $userId, $uid), 'Anket gönderim kuyruğuna alındı', 201);
    }
}
