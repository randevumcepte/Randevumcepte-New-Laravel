<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Ders\KatilimciDurumu;
use App\Http\Requests\Ders\KatilimciDurumIstegi;
use App\Http\Requests\Ders\KatilimciEkleIstegi;
use App\Services\Ders\DersKatilimServisi;
use App\Services\Ders\DersOturumServisi;
use App\Support\ApiCevap;

/**
 * Ders katilimcisi (eski /ders-katilimci-*).
 *
 *   POST   /ders/oturum/{id}/katilimci {musteri_id}  → {katilimci_id, katilimci_durum, telafi_baglandi}
 *   DELETE /ders/katilimci/{id}                      → cikar + bekleyen terfi
 *   PATCH  /ders/katilimci/{id}/durum {yeni_durum}   → {durum, dusum: dusuldu|hak_yok|hizmet_bagli_degil|iade|telafi_korundu|null}
 */
class DersKatilimciController extends DersTabanController
{
    public function __construct(
        private DersKatilimServisi $servis,
        private DersOturumServisi $oturum,
    ) {}

    public function ekle(KatilimciEkleIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->kayitSalonu($this->oturum->salonId($id), self::YAZ, 'Ders bulunamadı');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->ekle($id, (int) $request->validated('musteri_id'), $this->personelId($uid, $salonId), $uid,
            $this->mobilYolu() ? DersKatilimServisi::ZATEN_EKLI_MOBIL : DersKatilimServisi::ZATEN_KAYITLI), null, 201);
    }

    public function cikar(int $id)
    {
        [$uid] = $this->kayitSalonu($this->oturum->katilimciSalonId($id), self::SIL, 'Katılımcı bulunamadı');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->cikar($id, $uid, $this->mobilYolu()));
    }

    public function durum(KatilimciDurumIstegi $request, int $id)
    {
        [$uid] = $this->kayitSalonu($this->oturum->katilimciSalonId($id), self::OKU, 'Katılımcı bulunamadı');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->durumGuncelle($id, KatilimciDurumu::from($request->validated('yeni_durum')), $uid, $this->mobilYolu()));
    }
}
