<?php

namespace App\Services\Online;

use App\Services\Randevu\RandevuFormServisi;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;

/**
 * Randevu sihirbazinin secenekleri (hizmet + personel). Eski HomeController@personeladiminagec ve
 * v1 randevuIcinGerekliVeriler(randevuAlSayfasi) / personelAdiminaGec karsiligi.
 *
 * Veri minimizasyonu: v1 musteri yolunda personelin maas + prim oranlarini da donduruyordu; burada YOK.
 */
class OnlineSecenekServisi
{
    public function __construct(private RandevuFormServisi $form) {}

    public function secenekler(int $salonId): array
    {
        $personeller = $this->personeller($salonId);

        return [
            'hizmetler'           => $this->hizmetler($salonId),
            'personeller'         => $personeller,
            'personel_hizmet_map' => $this->form->haritalar($salonId)['personel_hizmet_map'],
        ];
    }

    public function hizmetler(int $salonId): array
    {
        $kategoriler = DB::table('hizmet_kategorisi')->pluck('hizmet_kategorisi_adi', 'id');

        return array_values(array_map(fn ($h) => $h + [
            'kategori_adi'    => $kategoriler[$h['kategori_id'] ?? 0] ?? null,
            'baslangic_fiyat' => $h['fiyat'],
        ], array_filter($this->form->hizmetler($salonId, null), fn ($h) => $h['aktif'])));
    }

    /** Hizmeti yapabilen personel; o hizmete kimse atanmamissa tum uygun personel (eski kural). */
    public function hizmetPersonelleri(int $salonId, int $hizmetId): array
    {
        $idler = $this->uygunPersonelIdleri($salonId, $hizmetId);

        return array_values(array_filter($this->personeller($salonId), fn ($p) => in_array($p['id'], $idler, true)));
    }

    /** @return int[] */
    public function uygunPersonelIdleri(int $salonId, int $hizmetId): array
    {
        $tum = $this->aktifPersonelSorgusu($salonId)->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $atanan = DB::table('personel_sunulan_hizmetler')->where('hizmet_id', $hizmetId)->whereIn('personel_id', $tum)
            ->pluck('personel_id')->map(fn ($x) => (int) $x)->all();

        return $atanan ? array_values(array_intersect($tum, $atanan)) : $tum;
    }

    /** Musteriye gosterilebilir alanlar (acik liste). */
    public function personeller(int $salonId): array
    {
        return $this->aktifPersonelSorgusu($salonId)
            ->leftJoin('isletmeyetkilileri as y', 'y.id', '=', 'sp.yetkili_id')
            ->orderBy('sp.takvim_sirasi')->orderBy('sp.id')
            ->get(['sp.id', 'sp.personel_adi', 'sp.unvan', 'sp.cinsiyet', 'sp.uzmanlik', 'sp.yillik_tecrube', 'sp.instagram', 'sp.aciklama', 'y.name as yetkili_adi', 'y.profil_resim'])
            ->map(fn ($p) => [
                'id'             => (int) $p->id,
                'personel_adi'   => $p->yetkili_adi ?: $p->personel_adi,
                'unvan'          => $p->unvan,
                'cinsiyet'       => $p->cinsiyet,
                'uzmanlik'       => $p->uzmanlik,
                'yillik_tecrube' => $p->yillik_tecrube,
                'instagram'      => $p->instagram,
                'aciklama'       => $p->aciklama,
                'profil_resmi'   => EskiPanelLinki::mutlak($p->profil_resim),
            ])->all();
    }

    private function aktifPersonelSorgusu(int $salonId)
    {
        return DB::table('salon_personelleri as sp')->where('sp.salon_id', $salonId)->where('sp.aktif', 1)->where('sp.takvimde_gorunsun', 1);
    }
}
