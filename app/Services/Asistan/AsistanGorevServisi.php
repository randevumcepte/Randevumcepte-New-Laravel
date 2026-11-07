<?php

namespace App\Services\Asistan;

use App\Domain\Asistan\GorevSunumu;
use App\Domain\Asistan\GorevTuru;
use App\Domain\Asistan\MobilGorevGorunumu;
use App\Domain\Asistan\MobilGorevSunumu;
use App\Models\Randevular;
use App\Models\Salonlar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E-Asistan gorev listesi (OKUMA) — eski "Asistanım > Bugünkü/Yarınki Görevler"
 * (StoreAdminController::easistandata) ve mobil v1 /easistandata'nin TEK v2 karsiligi.
 *
 * Her tur icin tek sorgu (N+1 yok, yalniz gereken kolonlar), kartlar
 * GorevSunumu'nda uretilir, saate gore siralanip sayfalanir. Kapsam/rol denetimi
 * controller'da (GuvenlikKapisi::yoneticiDenetle).
 */
class AsistanGorevServisi
{
    private const MOBIL_SAYFA_BOYU = 10;

    /** @return array{data: array, current_page: int, last_page: int, per_page: int, total: int, tarih: string} */
    public function liste(int $salonId, string $gorevGunu, int $sayfa = 1, int $sayfaBoyu = 25): array
    {
        $danisan = Salonlar::danisanMi(DB::table('salonlar')->where('id', $salonId)->value('salon_turu_id'));

        $kartlar = [];
        foreach (GorevTuru::cases() as $tur) {
            $hedef = $tur->hedefTarih($gorevGunu);
            foreach ($this->sorgu($tur, $salonId, $hedef)->get() as $satir) {
                $kartlar[] = GorevSunumu::sun($tur, (array) $satir, $danisan);
            }
        }

        // Saate gore artan (saatsizler sona); esitlikte tur sirasi korunur.
        usort($kartlar, fn ($a, $b) => [$a['saat'] === null, $a['saat']] <=> [$b['saat'] === null, $b['saat']]);

        $toplam = count($kartlar);
        $sonSayfa = max(1, (int) ceil($toplam / $sayfaBoyu));
        $sayfa = min(max(1, $sayfa), $sonSayfa);

        return [
            'data'         => array_slice($kartlar, ($sayfa - 1) * $sayfaBoyu, $sayfaBoyu),
            'current_page' => $sayfa,
            'last_page'    => $sonSayfa,
            'per_page'     => $sayfaBoyu,
            'total'        => $toplam,
            'tarih'        => $gorevGunu,
        ];
    }

    /**
     * Mobil uygulamanin bugunku gorev listesi (eski easistandata + easistandatadashboard). Filtre, sira ve metinler
     * webden farklidir: randevu durum filtresi yok, kampanya D+1, musteri yalniz bugun eklenenler, sayfa 10.
     *
     * @return array{data: array, current_page: int, last_page: int, total: int}
     */
    public function mobilListe(int $salonId, string $gorevGunu, MobilGorevGorunumu $gorunum, int $sayfa = 1): array
    {
        $danisan = Salonlar::danisanMi(DB::table('salonlar')->where('id', $salonId)->value('salon_turu_id'));
        $yarin = date('Y-m-d', strtotime($gorevGunu.' +1 day'));
        $satirlar = [];

        foreach ($this->mobilRandevular($salonId, $yarin) as $s) {
            $satirlar[] = MobilGorevSunumu::randevu((array) $s, $danisan, $gorunum);
        }
        if ($gorunum === MobilGorevGorunumu::Liste) {
            foreach ($this->mobilMusteriler($salonId) as $s) {
                $satirlar[] = MobilGorevSunumu::musteri((array) $s);
            }
        }
        foreach ($this->mobilAlacaklar($salonId, date('Y-m-d', strtotime($gorevGunu.' +2 days'))) as $s) {
            $satirlar[] = MobilGorevSunumu::alacak((array) $s, $danisan, $gorunum);
        }
        foreach ($this->mobilKampanyalar($salonId, $yarin, $gorunum) as $s) {
            $satirlar[] = MobilGorevSunumu::kampanya((array) $s, $gorunum);
        }

        if ($gorunum === MobilGorevGorunumu::Ozet) {
            return ['data' => $satirlar, 'current_page' => 1, 'last_page' => 1, 'total' => count($satirlar)];
        }
        $toplam = count($satirlar);
        $sayfa = max(1, $sayfa);

        return [
            'data'         => array_slice($satirlar, ($sayfa - 1) * self::MOBIL_SAYFA_BOYU, self::MOBIL_SAYFA_BOYU),
            'current_page' => $sayfa,
            'last_page'    => max(1, (int) ceil($toplam / self::MOBIL_SAYFA_BOYU)),
            'total'        => $toplam,
        ];
    }

    private function mobilRandevular(int $salonId, string $tarih)
    {
        return DB::table('randevular as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.salon_id', $salonId)->where('r.user_id', '!=', Randevular::KAPALI_SAAT_USER_ID)
            ->whereDate('r.tarih', $tarih)->orderBy('r.id')
            ->get([
                'r.id', 'r.tarih', 'r.saat', 'r.arama_saat', 'r.hatirlatma_aramasi_yapildi', 'r.hatirlatma_ulasilamadi',
                'r.randevuya_gelecek', 'r.asistan_guncelledi', 'r.tekrar_arandi', 'r.tekrar_arama_tarih_saat',
                'r.hatirlatma_gorevi_iptal', 'u.name as musteri_ad',
            ]);
    }

    private function mobilMusteriler(int $salonId)
    {
        return DB::table('musteri_portfoy as mp')->join('users as u', 'u.id', '=', 'mp.user_id')
            ->where('mp.salon_id', $salonId)->where('mp.asistan_ekledi', 1)->whereDate('mp.created_at', date('Y-m-d'))->orderBy('mp.id')
            ->get(['mp.id', 'mp.created_at', 'u.name as musteri_ad']);
    }

    private function mobilAlacaklar(int $salonId, string $tarih)
    {
        return DB::table('alacaklar as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.salon_id', $salonId)->whereDate('a.planlanan_odeme_tarihi', $tarih)->orderBy('a.id')
            ->get([
                'a.id', 'a.tutar', 'a.planlanan_odeme_tarihi', 'a.taksitli_tahsilat_id', 'a.arama_saat', 'a.hatirlatma_aramasi_yapildi',
                'a.hatirlatma_ulasilamadi', 'a.odenecek', 'a.asistan_guncelledi', 'a.tekrar_arandi', 'a.tekrar_arama_tarih_saat',
                'a.hatirlatma_gorevi_iptal', 'u.name as musteri_ad',
            ]);
    }

    private function mobilKampanyalar(int $salonId, string $tarih, MobilGorevGorunumu $gorunum)
    {
        return DB::table('kampanya_yonetimi as k')->where('k.salon_id', $salonId)->whereDate('k.asistan_tarih_saat', $tarih)
            ->when($gorunum === MobilGorevGorunumu::Liste, fn ($q) => $q->where(fn ($w) => $w->whereNull('k.tanitim_gorev_iptal')->orWhere('k.tanitim_gorev_iptal', 0)))
            ->orderBy('k.id')
            ->get(['k.id', 'k.paket_isim', 'k.arama_ile_gonderim', 'k.sms_ile_gonderim', 'k.arama_yapildi', 'k.sms_gonderildi', 'k.asistan_tarih_saat', 'k.tanitim_gorev_iptal']);
    }

    /** Turun o gunku gorev satirlari — yalniz kart icin gereken kolonlar. */
    private function sorgu(GorevTuru $tur, int $salonId, string $tarih): Builder
    {
        return match ($tur) {
            // Eski web filtreleri: onayli, gelmedi-isaretli degil, hizmetli; erteleme
            // (onceki_tarih) dahil. Kapali saat bloklari (sentinel musteri) haric.
            GorevTuru::Randevu => DB::table('randevular as r')
                ->join('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.salon_id', $salonId)
                ->where('r.durum', Randevular::ONAYLANDI)
                ->where('r.user_id', '!=', Randevular::KAPALI_SAAT_USER_ID)
                ->where(fn ($q) => $q->whereNull('r.randevuya_geldi')->orWhere('r.randevuya_geldi', '!=', 0))
                ->where(fn ($q) => $q->where('r.tarih', $tarih)->orWhere('r.onceki_tarih', $tarih))
                ->whereExists(fn ($q) => $q->from('randevu_hizmetler as rh')->whereColumn('rh.randevu_id', 'r.id'))
                ->select([
                    'r.id', 'r.tarih', 'r.saat', 'r.arama_saat', 'r.hatirlatma_aramasi_yapildi',
                    'r.hatirlatma_ulasilamadi', 'r.randevuya_gelecek', 'r.asistan_guncelledi',
                    'r.tekrar_arandi', 'r.tekrar_arama_tarih_saat', 'r.hatirlatma_gorevi_iptal',
                    'u.name as musteri_ad',
                ]),

            GorevTuru::Alacak => DB::table('alacaklar as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.salon_id', $salonId)
                ->where(fn ($q) => $q->where('a.planlanan_odeme_tarihi', $tarih)->orWhere('a.onceki_planlanan_odeme_tarihi', $tarih))
                ->select([
                    'a.id', 'a.tutar', 'a.planlanan_odeme_tarihi', 'a.taksitli_tahsilat_id', 'a.senet_id',
                    'a.arama_saat', 'a.hatirlatma_aramasi_yapildi', 'a.hatirlatma_ulasilamadi', 'a.odenecek',
                    'a.asistan_guncelledi', 'a.tekrar_arandi', 'a.tekrar_arama_tarih_saat',
                    'a.hatirlatma_gorevi_iptal', 'u.name as musteri_ad',
                ]),

            GorevTuru::Kampanya => DB::table('kampanya_yonetimi as k')
                ->where('k.salon_id', $salonId)
                ->whereDate('k.asistan_tarih_saat', $tarih)
                ->select([
                    'k.id', 'k.paket_isim', 'k.arama_ile_gonderim', 'k.sms_ile_gonderim',
                    'k.arama_yapildi', 'k.sms_gonderildi', 'k.asistan_tarih_saat', 'k.tanitim_gorev_iptal',
                ]),

            GorevTuru::Musteri => DB::table('musteri_portfoy as mp')
                ->join('users as u', 'u.id', '=', 'mp.user_id')
                ->where('mp.salon_id', $salonId)
                ->where('mp.asistan_ekledi', 1)
                ->whereDate('mp.created_at', $tarih)
                ->select(['mp.id', 'mp.created_at', 'u.name as musteri_ad']),
        };
    }
}
