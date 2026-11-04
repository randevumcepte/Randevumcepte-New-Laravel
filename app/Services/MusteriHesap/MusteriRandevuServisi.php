<?php

namespace App\Services\MusteriHesap;

use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\Randevular;
use App\Services\Randevu\RandevuDurumServisi;
use App\Support\RandevuYazma;
use Illuminate\Support\Facades\DB;

/**
 * Musterinin kendi randevulari (eski /randevularim + /randevuiptalet, v1 randevular(musteriMi) +
 * randevuiptalet). Eski iptal ucunda sahiplik kontrolu yoktu: id'yi bilen herkes iptal edebiliyordu.
 */
class MusteriRandevuServisi
{
    public function __construct(private RandevuDurumServisi $durum) {}

    public function liste(int $userId, ?int $salonId, ?int $durum, int $sayfaBoyu): array
    {
        $sayfa = DB::table('randevular as r')->join('salonlar as s', 's.id', '=', 'r.salon_id')
            ->where('r.user_id', $userId)
            ->when($salonId, fn ($q) => $q->where('r.salon_id', $salonId))
            ->when($durum !== null, fn ($q) => $q->where('r.durum', $durum))
            ->orderByDesc('r.tarih')->orderByDesc('r.saat')
            ->paginate($sayfaBoyu, ['r.id', 'r.tarih', 'r.saat', 'r.saat_bitis', 'r.durum', 'r.notlar', 'r.salon_id', 'r.created_at', 's.salon_adi']);

        $kalemler = $this->kalemler(array_column($sayfa->items(), 'id'));

        return [
            'data'         => array_map(fn ($r) => $this->sunum($r, $kalemler[$r->id] ?? []), $sayfa->items()),
            'current_page' => $sayfa->currentPage(),
            'last_page'    => $sayfa->lastPage(),
            'total'        => $sayfa->total(),
        ];
    }

    public function iptal(int $userId, int $randevuId): array
    {
        $r = DB::table('randevular')->where('id', $randevuId)->where('user_id', $userId)->first(['id', 'salon_id', 'tarih', 'saat', 'durum']);
        if (! $r) {
            throw new BulunamadiException('Randevu bulunamadı');
        }
        if (! $this->iptalEdilebilir($r)) {
            throw new CakismaException('Bu randevu iptal edilemez.');
        }
        if (! RandevuYazma::acik((int) $r->salon_id)) {
            throw new CakismaException(RandevuYazma::KAPALI_MESAJ);
        }
        $this->durum->musteriIptal($randevuId);

        return ['randevu_id' => $randevuId, 'durum' => Randevular::KABUL_EDILMEDI];
    }

    private function iptalEdilebilir(object $r): bool
    {
        return (int) $r->durum < Randevular::IPTAL_EDILDI && strtotime($r->tarih.' '.$r->saat) > time();
    }

    private function kalemler(array $randevuIdler): array
    {
        if (! $randevuIdler) {
            return [];
        }

        return DB::table('randevu_hizmetler as rh')
            ->leftJoin('hizmetler as h', 'h.id', '=', 'rh.hizmet_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 'rh.personel_id')
            ->whereIn('rh.randevu_id', $randevuIdler)
            ->where(fn ($q) => $q->whereNull('rh.yardimci_personel')->orWhere('rh.yardimci_personel', '!=', 1))
            ->orderBy('rh.saat')
            ->get(['rh.randevu_id', 'rh.saat', 'rh.saat_bitis', 'h.hizmet_adi', 'p.personel_adi'])
            ->groupBy('randevu_id')->all();
    }

    /** Alan adlari Flutter Randevu.fromJson ile uyumlu (hizmetler[].hizmetler.hizmet_adi, salonlar.salon_adi). */
    private function sunum(object $r, $kalemler): array
    {
        return [
            'id'                => (int) $r->id,
            'tarih'             => $r->tarih,
            'saat'              => substr((string) $r->saat, 0, 5),
            'durum'             => (int) $r->durum,
            'musteri_notu'      => $r->notlar,
            'olusturulma'       => $r->created_at,
            'salon_id'          => (int) $r->salon_id,
            'salonlar'          => ['salon_adi' => $r->salon_adi],
            'iptal_edilebilir'  => $this->iptalEdilebilir($r),
            'hizmetler'         => collect($kalemler)->map(fn ($k) => [
                'saat'        => substr((string) $k->saat, 0, 5),
                'saat_bitis'  => substr((string) $k->saat_bitis, 0, 5),
                'hizmetler'   => ['hizmet_adi' => $k->hizmet_adi],
                'personeller' => ['personel_adi' => $k->personel_adi],
            ])->values()->all(),
        ];
    }
}
