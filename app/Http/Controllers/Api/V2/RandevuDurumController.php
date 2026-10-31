<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Services\Randevu\RandevuDurumServisi;
use App\Services\Randevu\RandevuOkumaServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Randevu DURUM uclari (Faz 2) — ince controller.
 *
 * Her uc 'dry_run' alir (VARSAYILAN true): plan doner, hicbir sey yazmaz.
 * dry_run=false ancak config('randevu.yazma_aktif') aciksa yazar; kapaliysa 409.
 *
 * Yetki: geldi/gelmedi/isaret-kaldir/iptal → randevu.duzenle_iptal,
 *        onayla → randevu.onayla.
 */
class RandevuDurumController extends Controller
{
    public function __construct(
        private RandevuDurumServisi $servis,
        private RandevuOkumaServisi $okuma,
        private GuvenlikKapisi $kapi,
    ) {}

    /** POST /api/v2/randevu/{id}/geldi */
    public function geldi(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->geldi($id, [
            'dogrulama_kodu'      => $request->input('dogrulama_kodu'),
            'kvkk_kodu'           => $request->input('kvkk_kodu'),
            'seans_secim_destek'  => $request->boolean('seans_secim_destek'),
            'secilen_seans_idler' => $request->has('secilen_seans_idler') ? (array) $request->input('secilen_seans_idler') : null,
            'seans_miktarlari'    => (array) $request->input('seans_miktarlari', []),
        ], $uid, $yaz));
    }

    /** POST /api/v2/randevu/{id}/gelmedi  (seans_dusumu: yok=sor, true/false) */
    public function gelmedi(Request $request, int $id)
    {
        $dusum = $request->has('seans_dusumu') ? $request->boolean('seans_dusumu') : null;

        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->gelmedi($id, $dusum, $uid, $yaz));
    }

    /** DELETE /api/v2/randevu/{id}/geldi-isareti */
    public function isaretKaldir(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->isaretKaldir($id, $uid, $yaz));
    }

    /** POST /api/v2/randevu/{id}/iptal */
    public function iptal(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.duzenle_iptal', fn ($uid, $yaz) => $this->servis->iptal($id, $uid, $yaz));
    }

    /** POST /api/v2/randevu/{id}/onayla */
    public function onayla(Request $request, int $id)
    {
        return $this->calistir($request, $id, 'randevu.onayla', fn ($uid, $yaz) => $this->servis->onayla($id, $uid, $yaz));
    }

    /** Ortak iskelet: kimlik → kapsam/yetki → dry-run/kill-switch → servis → zarf. */
    private function calistir(Request $request, int $id, string $yetki, callable $islem)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = $this->okuma->salonIdGetir($id);
        if (! $salonId) {
            return ApiCevap::hata('Randevu bulunamadı', 404);
        }

        $this->kapi->denetle($user->id, $salonId, [$yetki]);

        $yaz = ! $request->boolean('dry_run', true);
        if ($yaz && ! config('randevu.yazma_aktif')) {
            return ApiCevap::hata('Randevu v2 yazma şu an kapalı (yalnızca dry_run)', 409);
        }

        $sonuc = $islem((int) $user->id, $yaz);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj'] ?? null);
    }
}
