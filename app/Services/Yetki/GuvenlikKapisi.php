<?php

namespace App\Services\Yetki;

use App\Exceptions\YetkisizErisimException;

/**
 * Guvenlik Kapisi — her korumali ucun gectigi TEK denetim noktasi.
 *
 * Uc sirasiyla sunu dogrular (fail-closed; herhangi biri gecmezse 403 firlatir):
 *   1. KIMLIK  : yetkili_id var mi (kimlik middleware'den gelir; burada guvence).
 *   2. KAPSAM  : kullanici bu salona (isletme/sube) bagli mi?
 *   3. YETKI   : kullanicinin bu salonda gereken yetki anahtar(lar)i var mi?
 *
 * Yetki icin anahtar listesi verilirse OR-gate uygulanir (herhangi biri yeterli)
 * — eski monolitteki satisListeYetkiYoksa403 gibi "gor VEYA olustur VEYA tahsilat"
 * davranisini birebir karsilar.
 */
class GuvenlikKapisi
{
    public function __construct(
        private KapsamServisi $kapsam,
        private YetkiServisi $yetki,
    ) {}

    /**
     * @param array<string>|string $anahtarlar Gereken yetki anahtar(lar)i (OR-gate)
     *
     * @throws YetkisizErisimException
     */
    public function denetle($yetkiliId, $salonId, array|string $anahtarlar): void
    {
        if (! $yetkiliId) {
            throw new YetkisizErisimException('Oturum geçersiz');
        }

        if (! $this->kapsam->salonaBagliMi($yetkiliId, $salonId)) {
            throw new YetkisizErisimException('Bu işletmeye erişim yetkiniz yok');
        }

        foreach ((array) $anahtarlar as $anahtar) {
            if ($this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $anahtar)) {
                return; // OR-gate: bir tanesi yeterli
            }
        }

        throw new YetkisizErisimException('Bu işlem için yetkiniz yok');
    }
}
