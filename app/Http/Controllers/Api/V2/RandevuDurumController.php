<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Randevu\RandevuDurumServisi;
use App\Services\Randevu\RandevuMobilYetki;
use App\Services\Randevu\RandevuOkumaServisi;
use App\Services\Randevu\RandevuTahsilatServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\RandevuYazma;
use Illuminate\Http\Request;

/**
 * Randevu DURUM uclari (Faz 2) — ince controller.
 *
 * Her uc 'dry_run' alir (VARSAYILAN true): plan doner, hicbir sey yazmaz.
 * dry_run=false ancak RandevuYazma (config/randevu.php) aciksa yazar; kapaliysa 409.
 *
 * Yetki: geldi/gelmedi/isaret-kaldir/seans-telafisi/iptal → randevu.duzenle_iptal,
 *        onayla → randevu.onayla, tahsilat → satis.tahsilat_al.
 *
 * 'mobil=1' uygulama istegidir: eski mobil sonucu korunur (servis) ve eski mobilde yetki aranmayan
 * islerde kural RandevuMobilYetki'den gelir. Kimlik ve salon kapsami her yolda aranir.
 */
class RandevuDurumController extends V2Controller
{
    public function __construct(
        private RandevuDurumServisi $servis,
        private RandevuOkumaServisi $okuma,
        private RandevuTahsilatServisi $tahsilat,
        private GuvenlikKapisi $kapi,
        private RandevuMobilYetki $mobilYetki,
    ) {}

    /** POST /api/v2/randevu/{id}/tahsilat — adisyonu bul/ac, kalemleri ekle; adisyon_id doner. */
    public function tahsilat(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'satis.tahsilat_al', fn ($uid, $yaz) => $this->tahsilat->tahsilataGec($id, $uid, $yaz));
    }

    /** POST /api/v2/randevu/{id}/geldi */
    public function geldi(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->geldi($id, [
            'dogrulama_kodu'      => $request->input('dogrulama_kodu'),
            'kvkk_kodu'           => $request->input('kvkk_kodu'),
            'seans_secim_destek'  => $request->boolean('seans_secim_destek'),
            'secilen_seans_idler' => $request->has('secilen_seans_idler') ? (array) $request->input('secilen_seans_idler') : null,
            'seans_miktarlari'    => (array) $request->input('seans_miktarlari', []),
        ], $uid, $yaz, $request->boolean('mobil')));
    }

    /** POST /api/v2/randevu/{id}/gelmedi  (seans_dusumu: yok=sor, true/false) */
    public function gelmedi(Request $request, int $id)
    {
        $dusum = $request->has('seans_dusumu') ? $request->boolean('seans_dusumu') : null;

        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->gelmedi($id, $dusum, $uid, $yaz, $request->boolean('mobil')), $this->mobilYetki->salonUyesi(...));
    }

    /** DELETE /api/v2/randevu/{id}/geldi-isareti */
    public function isaretKaldir(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->isaretKaldir($id, $uid, $yaz, $request->boolean('mobil')));
    }

    /** POST /api/v2/randevu/{id}/seans-telafisi  (seanslari telafi hakkina al; telafi RANDEVUSU degil) */
    public function seansTelafisi(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->seansTelafisi($id, $uid, $yaz, $request->boolean('mobil')), $this->mobilYetki->salonUyesi(...));
    }

    /** POST /api/v2/randevu/{id}/iptal  (liste_menusu: mobil listedeki sessiz iptal, RandevuMobilYetki) */
    public function iptal(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->iptal($id, $uid, $yaz, $request->boolean('mobil')),
            $request->boolean('liste_menusu') ? $this->mobilYetki->listeIptali(...) : null);
    }

    /** POST /api/v2/randevu/{id}/onayla */
    public function onayla(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.onayla', fn ($uid, $yaz) => $this->servis->onayla($id, $uid, $yaz, $request->boolean('mobil')), $this->mobilYetki->onay(...));
    }

    /** Ortak iskelet: kimlik → kapsam/yetki → dry-run/kill-switch → servis → zarf. */
    private function calistir(Request $request, int $id, string $yetki, callable $islem, ?callable $mobilDenetim = null)
    {
        $user = $this->kullanici();

        $salonId = $this->okuma->salonIdGetir($id);
        if (! $salonId) {
            return ApiCevap::hata('Randevu bulunamadı', 404);
        }

        $request->boolean('mobil') && $mobilDenetim
            ? $mobilDenetim((int) $user->id, $salonId)
            : $this->kapi->denetle($user->id, $salonId, [$yetki]);

        $yaz = RandevuYazma::yazilacakMi($request, $salonId);

        $sonuc = $islem((int) $user->id, $yaz);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj'] ?? null);
    }
}
