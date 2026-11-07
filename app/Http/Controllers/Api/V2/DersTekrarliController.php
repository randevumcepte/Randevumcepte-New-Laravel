<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\YetkisizErisimException;
use App\Http\Requests\Ders\TekrarliIstegi;
use App\Models\DersTekrarliKatilim;
use App\Models\Salonlar;
use App\Services\Ders\DersTekrarliServisi;
use App\Services\Studyo\StudyoPolitikaServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Otomatik ders plani — yalniz stüdyo modunda (eski /ders-tekrarli-*).
 *
 *   GET    /ders/tekrarli/kaynaklar?salon_id=&musteri_id=  kalan seansli satislar + haftalik slotlar
 *   GET    /ders/tekrarli?salon_id=&musteri_id=
 *   POST   /ders/tekrarli {salon_id, musteri_id, hizmet_id, toplam_seans, sablonlar[]|gunler[], ...} → {kayit_id, sonuc}
 *   PATCH  /ders/tekrarli/{id}                              gelecek yeniden dagitilir, gecmis korunur
 *   DELETE /ders/tekrarli/{id}
 */
class DersTekrarliController extends DersTabanController
{
    public function __construct(
        private DersTekrarliServisi $servis,
        private StudyoPolitikaServisi $politika,
    ) {}

    public function kaynaklar(Request $request)
    {
        [, $salonId] = $this->dersSalonu($request, self::YAZ);
        $this->studyoZorunlu($salonId);
        $request->validate(['musteri_id' => ['required', 'integer']]);

        return ApiCevap::basarili($this->mobilYolu()
            ? ['kaynaklar' => $this->servis->kaynaklarOrtalama($salonId, $request->integer('musteri_id'))]
            : $this->servis->kaynaklar($salonId, $request->integer('musteri_id')));
    }

    public function liste(Request $request)
    {
        [, $salonId] = $this->dersSalonu($request, self::YAZ);

        return ApiCevap::basarili($this->servis->liste($salonId, $request->integer('musteri_id') ?: null));
    }

    public function ekle(TekrarliIstegi $request)
    {
        [$uid, $salonId] = $this->dersSalonu($request, self::YAZ);
        $this->studyoZorunlu($salonId);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->kaydet($salonId, null, $request->validated(), $uid, $this->mobilYolu()), 'Ders planı oluşturuldu.', 201);
    }

    public function guncelle(TekrarliIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->planSalonu($id);
        $this->studyoZorunlu($salonId);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->kaydet($salonId, $id, $request->validated(), $uid), 'Ders planı güncellendi.');
    }

    public function sil(int $id)
    {
        [$uid, $salonId] = $this->planSalonu($id);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->servis->sil($salonId, $id, $uid, ! $this->mobilYolu()), 'Ders planı silindi.');
    }

    /** @return array{0:int,1:int} */
    private function planSalonu(int $id): array
    {
        return $this->kayitSalonu(DersTekrarliKatilim::whereKey($id)->value('salon_id'), self::YAZ, 'Kayıt bulunamadı');
    }

    private function studyoZorunlu(int $salonId): void
    {
        // Mobil yalniz stüdyo modunu arar, modul kapali stüdyoda da calisir.
        $acik = $this->mobilYolu() ? Salonlar::studyoModu($salonId) : $this->politika->getir($salonId)->tekrarliKatilim();
        if (! $acik) {
            throw new YetkisizErisimException('Otomatik ders planı yalnızca stüdyo modunda kullanılır');
        }
    }
}
