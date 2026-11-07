<?php

namespace App\Services\Ozet;

use App\Domain\Ozet\MobilKarsilastirmaHesap as Hesap;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mobil "Karsilastirma" karti — eski ApiController::dashboardKarsilastirma sorgulariyla birebir. Web'in
 * OzetMetrikServisi donem ve kapsami farkli hesaplar; mobil sayilar degismesin diye ayri servistir.
 */
class MobilKarsilastirmaServisi
{
    private const ONBELLEK_SN = 120;

    private const KALEMLER = [
        ['tahsilat_hizmetler', 'adisyon_hizmetler', 'adisyon_hizmet_id'],
        ['tahsilat_urunler', 'adisyon_urunler', 'adisyon_urun_id'],
        ['tahsilat_paketler', 'adisyon_paketler', 'adisyon_paket_id'],
    ];

    /** Kampanya kaydi onbellegi `dashKarsVer_{salon}` ile dusurur (SaatBosluguKampanyaYazServisi). */
    public function veri(int $salonId, string $donem, ?int $personelId): array
    {
        $surum = (int) Cache::get('dashKarsVer_'.$salonId, 0);
        $anahtar = 'mobilKars_'.$salonId.'_'.$donem.'_'.($personelId ?: 'all').'_v'.$surum;

        return Cache::remember($anahtar, self::ONBELLEK_SN, fn () => $this->hesapla($salonId, $donem, $personelId));
    }

    private function hesapla(int $salonId, string $donem, ?int $personelId): array
    {
        $simdi = Carbon::now();
        [$bas, $bit, $oncekiBas, $oncekiBit] = Hesap::araliklar($donem, $simdi);

        $ciro = $this->ciro($salonId, $bas, $bit, $personelId);
        $oncekiCiro = $this->ciro($salonId, $oncekiBas, $oncekiBit, $personelId);

        // Masraf salon geneli; personel kapsaminda 0 kalir.
        $maliyet = $personelId ? 0.0 : (float) DB::table('masraflar')->where('salon_id', $salonId)
            ->whereBetween('tarih', [$bas->toDateString(), $bit->toDateString()])->sum('tutar');

        $seri = [];
        for ($i = Hesap::SERI_UZUNLUK - 1; $i >= 0; $i--) {
            [$s, $e] = Hesap::geriAralik($donem, $i, $simdi);
            $seri[] = $this->ciro($salonId, $s, $e, $personelId);
        }

        $saatler = $this->saatSayilari($salonId, $bas, $bit, $personelId);
        $enIyiPersonel = $this->enIyiPersonel($salonId, $bas, $bit, $personelId);
        $enIyiHizmet = $this->enIyiHizmet($salonId, $bas, $bit, $personelId);
        $enIyiUrun = $this->enIyiUrun($salonId, $bas, $bit, $personelId);

        return [
            'period'         => $donem,
            'current'        => ['label' => Hesap::etiket($donem, true), 'value' => $ciro],
            'previous'       => ['label' => Hesap::etiket($donem, false), 'value' => $oncekiCiro],
            'series'         => $seri,
            'kasa'           => $ciro - $maliyet,
            'maliyet'        => $maliyet,
            'kar'            => $ciro - $maliyet,
            'alacak'         => $this->alacak($salonId, $bas->toDateString(), $bit->toDateString(), $personelId),
            'topPersonel'    => $enIyiPersonel ? ['name' => $enIyiPersonel->name, 'value' => (float) $enIyiPersonel->value] : null,
            'topHizmet'      => $enIyiHizmet ? ['name' => $enIyiHizmet->name, 'count' => (int) $enIyiHizmet->count] : null,
            'topUrun'        => $enIyiUrun ? ['name' => $enIyiUrun->name, 'count' => (int) $enIyiUrun->count] : null,
            'hourlyDensity'  => Hesap::saatYogunlugu($saatler),
            'hourlyAnalysis' => $this->saatAnalizi($salonId, $bas, $bit, $donem, $saatler, $simdi),
            'subeler'        => [],
        ];
    }

    private function ciro(int $salonId, Carbon $bas, Carbon $bit, ?int $personelId): float
    {
        $toplam = 0.0;
        foreach (self::KALEMLER as [$tablo, $kalem, $kolon]) {
            $q = DB::table($tablo)
                ->join('tahsilatlar', 'tahsilatlar.id', '=', $tablo.'.tahsilat_id')
                ->where('tahsilatlar.salon_id', $salonId)
                ->whereBetween('tahsilatlar.odeme_tarihi', [$bas, $bit]);
            if ($personelId) {
                $q->join($kalem, $kalem.'.id', '=', $tablo.'.'.$kolon)->where($kalem.'.personel_id', $personelId);
            }
            $toplam += (float) $q->sum($tablo.'.tutar');
        }

        return $toplam;
    }

    private function alacak(int $salonId, string $bas, string $bit, ?int $personelId): float
    {
        $q = DB::table('alacaklar')->where('alacaklar.salon_id', $salonId)
            ->whereBetween('alacaklar.planlanan_odeme_tarihi', [$bas, $bit]);
        self::alacakPersonelSuzgeci($q, $personelId);

        return (float) $q->sum('alacaklar.tutar');
    }

    /** Alacak personelin adisyon kaleminden dogmus olmali (eski ozet ve karsilastirma ayni kural). */
    public static function alacakPersonelSuzgeci($q, ?int $personelId): void
    {
        if (! $personelId) {
            return;
        }
        $q->whereNotNull('alacaklar.adisyon_id')->where(function ($dis) use ($personelId) {
            foreach (['adisyon_hizmetler', 'adisyon_urunler', 'adisyon_paketler'] as $kalem) {
                $dis->orWhereExists(fn ($alt) => $alt->select(DB::raw(1))->from($kalem)
                    ->whereColumn($kalem.'.adisyon_id', 'alacaklar.adisyon_id')
                    ->where($kalem.'.personel_id', $personelId));
            }
        });
    }

    private function enIyiPersonel(int $salonId, Carbon $bas, Carbon $bit, ?int $personelId): ?object
    {
        return DB::table('tahsilat_hizmetler')
            ->join('adisyon_hizmetler', 'adisyon_hizmetler.id', '=', 'tahsilat_hizmetler.adisyon_hizmet_id')
            ->join('tahsilatlar', 'tahsilatlar.id', '=', 'tahsilat_hizmetler.tahsilat_id')
            ->join('salon_personelleri as sp', 'sp.id', '=', 'adisyon_hizmetler.personel_id')
            ->where('tahsilatlar.salon_id', $salonId)
            ->whereBetween('tahsilatlar.odeme_tarihi', [$bas, $bit])
            ->when($personelId, fn ($q) => $q->where('adisyon_hizmetler.personel_id', $personelId))
            ->groupBy('sp.id', 'sp.personel_adi')
            ->select('sp.personel_adi as name', DB::raw('SUM(tahsilat_hizmetler.tutar) as value'))
            ->orderByDesc('value')
            ->first();
    }

    private function enIyiHizmet(int $salonId, Carbon $bas, Carbon $bit, ?int $personelId): ?object
    {
        return DB::table('adisyon_hizmetler')
            ->join('hizmetler', 'hizmetler.id', '=', 'adisyon_hizmetler.hizmet_id')
            ->join('adisyonlar', 'adisyonlar.id', '=', 'adisyon_hizmetler.adisyon_id')
            ->where('adisyonlar.salon_id', $salonId)
            ->whereBetween('adisyonlar.created_at', [$bas, $bit])
            ->when($personelId, fn ($q) => $q->where('adisyon_hizmetler.personel_id', $personelId))
            ->groupBy('hizmetler.id', 'hizmetler.hizmet_adi')
            ->select('hizmetler.hizmet_adi as name', DB::raw('COUNT(*) as count'))
            ->orderByDesc('count')
            ->first();
    }

    private function enIyiUrun(int $salonId, Carbon $bas, Carbon $bit, ?int $personelId): ?object
    {
        return DB::table('adisyon_urunler')
            ->join('urunler', 'urunler.id', '=', 'adisyon_urunler.urun_id')
            ->join('adisyonlar', 'adisyonlar.id', '=', 'adisyon_urunler.adisyon_id')
            ->where('adisyonlar.salon_id', $salonId)
            ->whereBetween('adisyonlar.created_at', [$bas, $bit])
            ->when($personelId, fn ($q) => $q->where('adisyon_urunler.personel_id', $personelId))
            ->groupBy('urunler.id', 'urunler.urun_adi')
            ->select('urunler.urun_adi as name', DB::raw('SUM(adisyon_urunler.adet) as count'))
            ->orderByDesc('count')
            ->first();
    }

    /** @return array<int,int|string> saat => randevu sayisi */
    private function saatSayilari(int $salonId, Carbon $bas, Carbon $bit, ?int $personelId): array
    {
        $q = DB::table('randevular')
            ->where('randevular.salon_id', $salonId)
            ->where('randevular.user_id', '!=', Hesap::SAAT_KAPAMA_MUSTERISI)
            ->whereBetween('randevular.tarih', [$bas->toDateString(), $bit->toDateString()]);
        if ($personelId) {
            $q->join('randevu_hizmetler', 'randevu_hizmetler.randevu_id', '=', 'randevular.id')
                ->where('randevu_hizmetler.personel_id', $personelId);
        }

        return $q->select(DB::raw('HOUR(randevular.saat) as h'), DB::raw('COUNT(DISTINCT randevular.id) as cnt'))
            ->groupBy('h')->pluck('cnt', 'h')->toArray();
    }

    private function saatAnalizi(int $salonId, Carbon $bas, Carbon $bit, string $donem, array $saatler, Carbon $simdi): array
    {
        $calisma = DB::table('salon_calisma_saatleri')->where('salon_id', $salonId)->where('calisiyor', 1)
            ->whereIn('haftanin_gunu', Hesap::haftaGunleri($bas, $bit))
            ->get(['baslangic_saati', 'bitis_saati']);

        $kampanyalar = DB::table('kampanyalar')->where('salon_id', $salonId)->where('onayli', 1)
            ->where('kampanya_bitis_tarihi', '>=', $simdi->toDateTimeString())
            ->where('kampanya_detay', 'like', '%[gap:%')
            ->orderByDesc('id')
            ->get(['id', 'kampanya_baslik', 'kampanya_aciklama', 'kampanya_detay', 'kampanya_bitis_tarihi']);

        return Hesap::saatAnalizi($saatler, $calisma, $kampanyalar, $donem, $simdi);
    }
}
