<?php

namespace App\Services\Oturum;

use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;

/**
 * Web giristen sonra acilacak ilk v2 ekrani (eski LoginController::_yetkiliIlkSayfa, sidebar sirasiyla).
 */
class IlkSayfa
{
    private const VARSAYILAN = '/v2/profil';

    private const PERSONEL_ROLU = 5;

    /** Sira onemli: ilk eslesen yetki kazanir. */
    private const SIRA = [
        ['/v2/ozet', ['randevu.takvim_gor', 'musteri.liste_gor'], 'personele_kapali' => true],
        ['/v2/takvim', ['randevu.takvim_gor']],
        ['/v2/musteriler', ['musteri.liste_gor']],
        ['/v2/satislar', ['satis.adisyon_olustur', 'satis.tahsilat_al', 'satis.tum_satis_gor']],
        ['/v2/raporlar', ['rapor.satis']],
        ['/v2/seans-takibi', ['paket.seans_takip']],
        ['/v2/personel', ['personel.liste_gor']],
        ['/v2/kasa', ['rapor.kasa', 'finans.kasa_giris_cikis', 'finans.masraf_gor']],
        ['/v2/reklam-yonetimi', ['pazarlama.kampanya_yonet']],
        ['/v2/sms-yonetimi', ['pazarlama.sms_gonder', 'pazarlama.toplu_sms']],
        ['/v2/ayarlar', ['ayar.salon_bilgi', 'ayar.cihaz_oda_yonet', 'randevu.online_ayar']],
    ];

    public function __construct(private YetkiServisi $yetki, private KapsamServisi $kapsam) {}

    public function coz(int $yetkiliId, int $salonId): string
    {
        $personel = in_array(self::PERSONEL_ROLU, $this->kapsam->salonRolleri($yetkiliId, $salonId), true);
        foreach (self::SIRA as $satir) {
            if ($personel && ! empty($satir['personele_kapali'])) {
                continue;
            }
            foreach ($satir[1] as $anahtar) {
                if ($this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $anahtar)) {
                    return $satir[0].'?sube='.$salonId;
                }
            }
        }

        return self::VARSAYILAN.'?sube='.$salonId;
    }
}
