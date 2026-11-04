<?php

namespace App\Services\Ozet;

use App\Domain\Ozet\OzetEylemi;
use App\Domain\Ozet\OzetKarti;
use App\Domain\Ozet\OzetSekmesi;
use App\Domain\Rapor\RaporDonemi;
use App\Models\Salonlar;
use App\Services\Panel\PanelKabukServisi;
use App\Services\Rapor\RaporSecenekServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\PanelMenu;
use Illuminate\Support\Facades\DB;

/**
 * Ozet ekraninin ACILIS verisi + kart kapsam kurallari — TEK YER.
 *
 * Web /v2/ozet ve Flutter ana ekrani buradan hangi kartin / sekmenin / hizli eylemin
 * cizilecegini, donem seceneklerini ve "bugunun ozeti" sayaclarini alir (UI'da yetki
 * kurali yok). Kart veri uclarinin kapisi da ayni OzetKarti tanimini kullanir.
 */
class OzetAcilisServisi
{
    public function __construct(
        private YetkiServisi $yetki,
        private RaporSecenekServisi $secenek,
        private OzetMetrikServisi $metrik,
    ) {}

    public function acilis(int $yetkiliId, int $salonId): array
    {
        $var = fn (string $a) => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $a);
        $salon = DB::table('salonlar')->where('id', $salonId)->first(['salon_adi', 'studyo_modu', 'uyelik_bitis_tarihi']);
        $studyo = (int) ($salon->studyo_modu ?? 0) === 1;
        $rol5 = $this->secenek->zorunluPersonel($yetkiliId, $salonId);

        $kartlar = array_values(array_filter(OzetKarti::cases(), fn (OzetKarti $k) => ! ($studyo && $k->studyoGizle())
            && array_filter($k->yetkiler(), $var)));

        $bugun = $this->metrik->bugun($salonId, $rol5,
            $rol5 !== null || $var('randevu.takvim_gor'),
            $rol5 !== null || $var('rapor.satis'));
        if ($studyo || ! ($rol5 !== null || $var('gorusme.liste_gor'))) {
            $bugun['on_gorusme'] = null;
        }
        if ($studyo) {
            $bugun['urun_satis'] = null;
        }
        $bugun['gelir'] = $var('rapor.kasa') && $rol5 === null
            ? $this->metrik->kasa($salonId, RaporDonemi::coz('bugun', null, null), null)['toplam'] : null;

        return [
            'salon'     => ['ad' => trim((string) ($salon->salon_adi ?? '')), 'studyo_modu' => $studyo, 'lisans_gun' => PanelKabukServisi::lisansGun($salon)],
            'personel_kapsami' => $rol5,
            'bugun'     => $bugun,
            'kartlar'   => array_map(fn (OzetKarti $k) => [
                'kod'              => $k->value,
                'baslik'           => $k->baslik(),
                'ikon'             => $k->ikon(),
                'donemler'         => $k->donemSecenekleri(),
                'varsayilan_donem' => $k->varsayilanDonem(),
            ], $kartlar),
            'sekmeler'  => array_values(array_map(fn (OzetSekmesi $s) => [
                'kod'       => $s->value,
                'ad'        => $s->ad(),
                'ikon'      => $s->ikon(),
                'yeni'      => $s->yeni(),
                'bos_mesaj' => $s->bosMesaj(),
                'kolonlar'  => $s->kolonlar(),
            ], array_filter(OzetSekmesi::cases(), fn (OzetSekmesi $s) => $var($s->yetki())))),
            'eylemler'  => array_values(array_map(fn (OzetEylemi $e) => [
                'kod'    => $e->value,
                'etiket' => $e->etiket(),
                'ikon'   => $e->ikon(),
                'url'    => ($m = PanelMenu::bul($e->menuSlug())) ? PanelMenu::url($m, $salonId, $e->ekSorgu()) : null,
            ], array_filter(OzetEylemi::cases(), fn (OzetEylemi $e) => $var($e->yetki())))),
        ];
    }

    /**
     * Kartin personel kapsami: rol 5 → daima kendi kayitlari; yonetici $tamYetki'ye sahipse
     * tum salon (null), degilse (yalniz personel.kendi_ciro_gor) kendi kalemleri.
     * Eski web kasa karti + mobil karsilastirma kurali (istemciden gelen personel_id'ye GUVENILMEZ).
     */
    public function personelKapsami(int $yetkiliId, int $salonId, string $tamYetki): ?int
    {
        $rol5 = $this->secenek->zorunluPersonel($yetkiliId, $salonId);
        if ($rol5 !== null || $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $tamYetki)) {
            return $rol5;
        }
        return $this->secenek->bagliPersonel($yetkiliId, $salonId);
    }

    /** Studyo modunda gizli kart mi (veri ucu 404 doner, kapsam sizintisi yok). */
    public function kartGizli(int $salonId, OzetKarti $kart): bool
    {
        return $kart->studyoGizle() && Salonlar::studyoModu($salonId);
    }
}
