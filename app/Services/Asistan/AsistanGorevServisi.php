<?php

namespace App\Services\Asistan;

use App\Domain\Asistan\GorevSunumu;
use App\Domain\Asistan\GorevTuru;
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
