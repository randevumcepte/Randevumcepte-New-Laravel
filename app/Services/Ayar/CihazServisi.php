<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\KaynakTuru;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Ayarlar → Cihazlar: eski cihazekleduzenle + v1 cihazekle/cihazgetir'in TEK karsiligi.
 * Ortak liste/durum/sil/sira TakvimKaynakServisi'nde; 7 gunluk plan HaftalikPlanServisi'nde.
 *
 * Eskiden farkli: eski web formu `cihaz_calisiyorN` gonderip sunucu `calisiyorN` okudugu icin
 * her cihaz "hic calismiyor" kaydediliyordu — v2 plani gonderildigi gibi yazar. Eskide cihaz
 * duzenleme yoktu; v2'de ad + plan guncellenebilir (bozuk kayitlari duzeltmek icin).
 */
class CihazServisi implements TakvimKaynakYonetimi
{
    private const TUR = KaynakTuru::Cihaz;

    public function __construct(
        private TakvimKaynakServisi $kaynak,
        private HaftalikPlanServisi $plan,
    ) {}

    public function tur(): KaynakTuru
    {
        return self::TUR;
    }

    /** @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $o */
    public function listele(int $salonId, array $o, bool $tumu = false): array
    {
        return $this->kaynak->listele(self::TUR, $salonId, $o, $tumu);
    }

    public function detay(int $salonId, int $id): array
    {
        return $this->kaynak->satir(self::TUR, $salonId, $id) + ['gunler' => $this->plan->oku('cihaz', $id)];
    }

    /** Bos cihaz formu icin varsayilan plan (web/Flutter sabit tutmaz). */
    public function formVerisi(int $salonId): array
    {
        return ['gunler' => $this->plan->oku('cihaz', 0)];
    }

    /** @param  array{cihaz_adi?:string, gunler?:list<array>}  $g */
    public function kaydet(int $salonId, int $yapanId, ?int $id, array $g): array
    {
        $yeni = $id === null;
        if (! $yeni) {
            $this->kaynak->bul(self::TUR, $salonId, $id);
        }

        $id = DB::transaction(function () use ($salonId, $id, $g) {
            if ($id === null) {
                $id = $this->kaynak->olustur(self::TUR, $salonId, trim($g['cihaz_adi']));
            } elseif (isset($g['cihaz_adi'])) {
                $this->kaynak->adGuncelle(self::TUR, $id, trim($g['cihaz_adi']));
            }
            if (isset($g['gunler'])) {
                $this->plan->yaz('cihaz', $id, $g['gunler']);
            }

            return $id;
        });

        $detay = $this->detay($salonId, $id);
        AktiviteLog::yaz($salonId, $yapanId, $yeni ? 'cihaz_ekle' : 'cihaz_guncelle', 'cihaz', $id,
            $detay['ad'], $yeni ? 'Yeni cihaz eklendi' : 'Cihaz bilgileri güncellendi');

        return $detay;
    }
}
