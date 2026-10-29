<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Controllers\Controller;
use App\Models\Personeller;
use App\Models\Tahsilat;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use App\Services\Tahsilat\TahsilatSilServisi;
use App\Services\Tahsilat\TahsilatYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Tahsilat YAZMA API v2 — şimdilik yalnızca DRY-RUN.
 * KVKK: kimlik + kapsam (adisyon bu işletmeye mi ait) + satis.tahsilat_al.
 * Gerçek yazma KAPALI (dry_run=1 zorunlu); kullanıcı onayıyla açılacak.
 */
class TahsilatController extends Controller
{
    public function __construct(
        private TahsilatYazServisi $servis,
        private TahsilatSilServisi $silServisi,
        private AdisyonOkumaServisi $adisyonServisi,
        private GuvenlikKapisi $kapi,
    ) {}

    /** POST /api/v2/tahsilat */
    public function ekle(Request $request)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            return ApiCevap::hata('salon_id zorunlu', 422);
        }

        $adisyonId = (int) $request->input('adisyon_id');
        if (! $adisyonId) {
            return ApiCevap::hata('adisyon_id zorunlu', 422);
        }

        // Tenant izolasyonu: adisyon GERÇEKTEN bu salona mı ait?
        $adisyonSalon = $this->adisyonServisi->salonIdGetir($adisyonId);
        if (! $adisyonSalon) {
            return ApiCevap::hata('Adisyon bulunamadı', 404);
        }
        if ($adisyonSalon !== $salonId) {
            throw new YetkisizErisimException('Adisyon bu işletmeye ait değil');
        }

        // Kapsam + yetki.
        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_al']);

        // GERÇEK YAZMA yolu — yalnızca dry_run=false iken.
        if (! $request->boolean('dry_run')) {
            // Çift güvenlik: config bayrağı kapalıysa asla yazma.
            if (! config('tahsilat.yazma_aktif')) {
                return ApiCevap::hata('Gerçek yazma henüz etkin değil (dry_run=1 zorunlu).', 409);
            }
            // Henüz desteklenmeyen akış: harici indirim (iade ile entangle — ayrı ele alınacak).
            if ((float) $request->input('indirim', 0) > 0) {
                return ApiCevap::hata('Harici indirim v2 yazmada henüz desteklenmiyor', 422);
            }
            // Taksit/senet vade ödemesi: ARTIK destekleniyor (vade modu).
            if (! $request->filled('idempotency_key')) {
                return ApiCevap::hata('idempotency_key zorunlu', 422);
            }

            $olusturanId = Personeller::where('salon_id', $salonId)
                ->where('yetkili_id', $user->id)->value('id');

            $sonuc = $this->servis->yaz(
                array_merge($request->all(), ['salon_id' => $salonId, 'adisyon_id' => $adisyonId]),
                ['yetkili_id' => $user->id, 'salon_id' => $salonId, 'olusturan_id' => $olusturanId ? (int) $olusturanId : null]
            );

            $sonuc['hesap'] = $this->adisyonServisi->getir($adisyonId)['hesap'];

            return ApiCevap::basarili(
                $sonuc,
                ($sonuc['replay'] ?? false) ? 'Bu istek zaten işlenmişti (replay — yeni kayıt yok).' : 'Tahsilat kaydedildi.'
            );
        }

        // DRY-RUN yolu — hiçbir şey yazmaz.
        $plan = $this->servis->planla(array_merge($request->all(), [
            'salon_id'   => $salonId,
            'adisyon_id' => $adisyonId,
        ]));

        return ApiCevap::basarili($plan, 'DRY-RUN: hiçbir şey yazılmadı.');
    }

    /**
     * DELETE /api/v2/tahsilat/{id} — tahsilat İADE/SİLME.
     * KVKK: kimlik + kapsam (tahsilatın salonu) + satis.tahsilat_sil. Flag korumalı.
     */
    public function sil(int $id)
    {
        $user = auth('isletmeyonetim-api')->user();
        if (! $user) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        // Gerçek yazma/iade flag'i (yazma ile aynı kapı).
        if (! config('tahsilat.yazma_aktif')) {
            return ApiCevap::hata('Gerçek iade/silme henüz etkin değil', 409);
        }

        $t = Tahsilat::find($id);
        if (! $t) {
            return ApiCevap::hata('Tahsilat bulunamadı', 404);
        }
        $salonId = (int) $t->salon_id;

        // Kapsam + yetki.
        $this->kapi->denetle($user->id, $salonId, ['satis.tahsilat_sil']);

        $sonuc = $this->silServisi->sil($id, ['yetkili_id' => $user->id, 'salon_id' => $salonId]);
        $sonuc['hesap'] = $this->adisyonServisi->getir($sonuc['adisyon_id'])['hesap'];

        return ApiCevap::basarili($sonuc, 'Tahsilat iade edildi (silindi).');
    }
}
