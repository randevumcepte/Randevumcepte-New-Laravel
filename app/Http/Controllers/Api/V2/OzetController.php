<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Ozet\OzetKarti;
use App\Domain\Ozet\OzetSekmesi;
use App\Domain\Rapor\RaporDonemi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Services\Ozet\OzetAcilisServisi;
use App\Services\Ozet\OzetListeServisi;
use App\Services\Ozet\OzetMetrikServisi;
use App\Services\Pazarlama\AnketOzetServisi;
use App\Services\Pazarlama\CarkOzetServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * OZET (dashboard) — eski isletmeyonetim/api/dashboard/* (web) + v1 /dashboard ve
 * /dashboardKarsilastirma (mobil, KIMLIKSIZDI) uclarinin TEK v2 karsiligi.
 *
 *   GET /ozet/acilis                       kartlar, sekmeler, hizli eylemler, bugunun sayaclari
 *   GET /ozet/kart/{kod}?donem=            kasa | randevu | anket | cark | akis | personel | karsilastirma
 *   GET /ozet/sekme/{kod}                  online_talep | bugunku_randevu | acik_adisyon | alacak | dogum_gunu
 *
 * Kapi: kimlik + kapsam + kartin/sekmenin KENDI yetkisi (OzetKarti / OzetSekmesi — acilis
 * listesiyle ayni tanim). Personel kapsami token'dan cozulur; istemci personel_id'si yok sayilir.
 */
class OzetController extends RaporTabanController
{
    public function __construct(
        private OzetAcilisServisi $acilis,
        private OzetMetrikServisi $metrik,
        private OzetListeServisi $liste,
    ) {}

    public function acilis(Request $r)
    {
        [$uid, $salonId] = $this->salonDenetle($r, []);

        return ApiCevap::basarili($this->acilis->acilis($uid, $salonId));
    }

    public function kart(Request $r, string $kod)
    {
        $kart = OzetKarti::tryFrom($kod) ?? throw new BulunamadiException('Kart bulunamadı');
        [$uid, $salonId] = $this->salonDenetle($r, $kart->yetkiler());
        if ($this->acilis->kartGizli($salonId, $kart)) {
            throw new BulunamadiException('Kart bu işletmede kullanılmıyor');
        }
        $d = $this->kartDonemi($r, $kart);

        return ApiCevap::basarili(match ($kart) {
            OzetKarti::Kasa          => $this->metrik->kasa($salonId, $d, $this->acilis->personelKapsami($uid, $salonId, 'rapor.kasa')),
            OzetKarti::Randevu       => $this->metrik->randevu($salonId, $d),
            OzetKarti::Anket         => app(AnketOzetServisi::class)->ozet($salonId, $d),
            OzetKarti::Cark          => app(CarkOzetServisi::class)->ozet($salonId, $d),
            OzetKarti::Akis          => $this->metrik->akis($salonId),
            OzetKarti::Personel      => $this->metrik->personelDurum($salonId),
            OzetKarti::Karsilastirma => $this->karsilastirma($uid, $salonId, $d),
        });
    }

    public function sekme(Request $r, string $kod)
    {
        $sekme = OzetSekmesi::tryFrom($kod) ?? throw new BulunamadiException('Sekme bulunamadı');
        [$uid, $salonId] = $this->salonDenetle($r, $sekme->yetki());

        // Randevu sekmelerinde tum personeli gormeyen hesap yalniz kendi randevularini gorur.
        $randevuSekmesi = in_array($sekme, [OzetSekmesi::OnlineTalep, OzetSekmesi::BugunkuRandevu], true);
        $personel = $randevuSekmesi && ! $this->yetkiVar($uid, $salonId, 'randevu.tum_personel_gor')
            ? $this->acilis->personelKapsami($uid, $salonId, 'randevu.tum_personel_gor') : null;

        return ApiCevap::basarili($this->liste->liste($salonId, $sekme, $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'), $personel));
    }

    private function karsilastirma(int $uid, int $salonId, RaporDonemi $d): array
    {
        $personel = $this->acilis->personelKapsami($uid, $salonId, 'rapor.satis');
        $liderler = $personel === null && $this->yetkiVar($uid, $salonId, 'rapor.personel_performans');

        return $this->metrik->karsilastirma($salonId, $d, $personel, $liderler);
    }

    /** Kartin izinli donemlerinden biri (bos → varsayilan); donemsiz kartta bugun. */
    private function kartDonemi(Request $r, OzetKarti $kart): RaporDonemi
    {
        $izinli = $kart->donemler();
        $donem = (string) $r->input('donem', '') ?: ($kart->varsayilanDonem() ?? 'bugun');
        if ($izinli && ! in_array($donem, $izinli, true)) {
            throw new GecersizIslemException('donem şunlardan biri olmalı: '.implode(', ', $izinli));
        }

        return RaporDonemi::coz($donem, null, null);
    }
}
