<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Kampanya\KatilimDurumu;
use App\Services\Kampanya\KatilimciServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * REKLAM RAPORU v2 — kampanya katılımcıları (eski reklam-detay-modal + v1 kampanyaDetay).
 *
 *   GET    /kampanya/{id}/katilimcilar?durum=1..7|kod + ListeIstegi → {data[], …meta, sayaclar{tumu, katilan, …}}
 *   POST   /kampanya/{id}/katilimcilar            {user_id}      → müşteriyi ekle
 *   DELETE /kampanya/{id}/katilimcilar/{kid}                     → çıkar (kupon kullanılmışsa 409)
 *   POST   /kampanya/{id}/tekrar-ara                             → ulaşılamayanları yeniden sıraya al (arama)
 *   POST   /kampanya/{id}/personele-ata           {personel_id, durum} → Çağrı Merkezi arama listesi
 *
 * Eski ad: katilimDurumu → durum (Flutter 1..4 sayılarıyla aynı).
 */
class KampanyaKatilimciController extends KampanyaTabanController
{
    public function __construct(private KatilimciServisi $katilimci) {}

    public function liste(Request $request, int $id)
    {
        [$uid, $salonId, $k] = $this->kampanya($id);
        $liste = ListeIstegi::istekten($request, 50, array_keys(KatilimciServisi::SIRALAMA), 'ad_soyad', 'asc')->dizi();

        return ApiCevap::basarili($this->katilimci->listele($k, $this->durum($request), $liste, $this->ctx($uid, $salonId)));
    }

    public function ekle(Request $request, int $id)
    {
        [$uid, $salonId, $k] = $this->kampanya($id);
        $v = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->katilimci->ekle($k, (int) $v['user_id'], $uid, $this->kisitPersonel($uid, $salonId)), 'Müşteri kampanyaya eklendi', 201);
    }

    public function sil(int $id, int $kid)
    {
        [$uid, , $k] = $this->kampanya($id);
        $this->yazmaAcikMi();
        $this->katilimci->sil($k, $kid, $uid);

        return ApiCevap::basarili(['id' => $kid], 'Katılımcı kampanyadan çıkarıldı');
    }

    public function tekrarAra(int $id)
    {
        [$uid, , $k] = $this->kampanya($id);
        $this->yazmaAcikMi();
        $adet = $this->katilimci->tekrarAra($k, $uid);

        return ApiCevap::basarili(['adet' => $adet], $adet ? $adet.' kişi yeniden aranmak üzere sıraya alındı' : 'Tekrar aranacak ulaşılamayan katılımcı yok');
    }

    public function personeleAta(Request $request, int $id)
    {
        [$uid, , $k] = $this->kampanya($id);
        $v = $request->validate(['personel_id' => ['required', 'integer', 'min:1']]);
        $this->yazmaAcikMi();
        $s = $this->katilimci->personeleAta($k, (int) $v['personel_id'], $this->durum($request), $uid);

        return ApiCevap::basarili($s, $s['mesaj'], 201);
    }

    private function durum(Request $request): KatilimDurumu
    {
        return KatilimDurumu::coz($request->input('durum', $request->input('katilimDurumu', $request->input('tur'))));
    }
}
