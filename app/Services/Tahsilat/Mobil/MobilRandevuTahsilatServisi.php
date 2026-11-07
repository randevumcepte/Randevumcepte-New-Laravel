<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Exceptions\BulunamadiException;
use App\Models\Adisyonlar;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Eski mobil randevutahsilet: adisyon secim kurali (paket adisyonu hizmet ADI ile, Tum Vucut yedegi; seans bagi; kapali ve
 * kismi odenmis adisyon; ayni gun birlestirme) satir satir. Web RandevuTahsilatServisi'ne dokunulmaz.
 */
class MobilRandevuTahsilatServisi
{
    public function __construct(private MobilTahsilatYazici $yazici) {}

    public function salonId(int $randevuId): ?int
    {
        $id = DB::table('randevular')->where('id', $randevuId)->value('salon_id');

        return $id ? (int) $id : null;
    }

    public function tahsilataGec(int $randevuId, int $yetkiliId, string $anahtar): array
    {
        $randevu = DB::table('randevular')->where('id', $randevuId)->first() ?? throw new BulunamadiException('Randevu bulunamadı');
        $salonId = (int) $randevu->salon_id;

        $sonuc = Idempotency::calistir('mobil_randevu_tahsilat', $anahtar, $yetkiliId, $salonId, fn () => $this->yaz($randevu, $salonId, $yetkiliId));

        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_tahsilat', 'randevu', $randevuId, null, 'Randevu tahsilati eklendi (adisyona islendi)');
        }

        return $sonuc;
    }

    private function yaz(object $randevu, int $salonId, int $yetkiliId): array
    {
        DB::table('randevular')->where('id', $randevu->id)->update(['tahsilat_eklendi' => 1, 'updated_at' => now()]);

        $hizmetler = $this->hizmetler((int) $randevu->id);
        $bulunan = $this->paketAdisyonu($randevu, $hizmetler);
        $seansAdisyonu = $this->seansAdisyonu($randevu);
        $adisyonId = $seansAdisyonu ?? $bulunan;

        if ($adisyonId && $this->tamamenOdendi($adisyonId)) {
            $adisyonId = null;
        }

        if ($adisyonId) {
            // Paket adisyonunda kismi odeme varsa uzerine yazilmaz.
            $adisyonId = $this->kismiOdendi($adisyonId) ? $this->yeniAdisyon($randevu, $salonId, $yetkiliId) : $adisyonId;
        } else {
            $adisyonId = $this->ayniGunAdisyonu($randevu, $salonId) ?? $this->yeniAdisyon($randevu, $salonId, $yetkiliId);
        }

        $this->hizmetleriEkle($randevu, $hizmetler, $adisyonId, $salonId);

        // Ayni gun ayni musterinin diger randevulari da tek adisyona. Hata tahsilati bozmaz, eski kod gibi.
        try {
            $digerleri = DB::table('randevular')->where('user_id', $randevu->user_id)->where('salon_id', $salonId)
                ->where('tarih', $randevu->tarih)->where('id', '!=', $randevu->id)->where('durum', '<', 2)->get();
            foreach ($digerleri as $d) {
                $this->hizmetleriEkle($d, $this->hizmetler((int) $d->id), $adisyonId, $salonId);
            }
        } catch (\Throwable $e) {
            Log::warning('mobil randevutahsilet ayni gun birlestirme hata: '.$e->getMessage(), ['randevu_id' => $randevu->id]);
        }

        return ['adisyon_id' => (string) $adisyonId, 'user_id' => (string) $randevu->user_id];
    }

    private function hizmetler(int $randevuId)
    {
        return DB::table('randevu_hizmetler as rh')->leftJoin('hizmetler as h', 'h.id', '=', 'rh.hizmet_id')
            ->where('rh.randevu_id', $randevuId)->orderBy('rh.id')->get(['rh.*', 'h.hizmet_adi']);
    }

    /** Hizmet adinin "+" parcalariyla LIKE arama; ilk parca oncelikli. Tum Vucut parcasi filtresiz yedege duser. */
    private function paketAdisyonu(object $randevu, $hizmetler): ?int
    {
        $adlar = [];
        $tumVucut = false;
        foreach ($hizmetler as $h) {
            foreach (explode('+', (string) $h->hizmet_adi) as $parca) {
                $parca = trim($parca);
                if ($parca === '') {
                    continue;
                }
                if (str_contains($parca, 'Tüm Vücut')) {
                    $tumVucut = true;

                    continue;
                }
                $adlar[] = $parca;
            }
        }

        $sorgu = function (bool $filtreli) use ($randevu, $adlar) {
            $q = DB::table('adisyonlar')
                ->join('adisyon_paketler', 'adisyonlar.id', '=', 'adisyon_paketler.adisyon_id')
                ->join('adisyon_paket_seanslar', 'adisyon_paketler.id', '=', 'adisyon_paket_seanslar.adisyon_paket_id')
                ->join('paketler', 'adisyon_paketler.paket_id', '=', 'paketler.id')
                ->join('paket_hizmetler', 'paketler.id', '=', 'paket_hizmetler.paket_id')
                ->join('hizmetler', 'paket_hizmetler.hizmet_id', '=', 'hizmetler.id')
                ->select('adisyonlar.id as adisyon_id', DB::raw('(SELECT COUNT(*) from adisyon_paket_seanslar where adisyon_paket_seanslar.geldi is null and adisyon_paket_seanslar.adisyon_paket_id = adisyon_paketler.id) as gelinmeyen_seans_sayisi'))
                ->where('adisyonlar.salon_id', $randevu->salon_id)->where('adisyonlar.user_id', $randevu->user_id)
                ->havingRaw('gelinmeyen_seans_sayisi > 0');
            if ($filtreli && $adlar) {
                $q->where(function ($w) use ($adlar) {
                    foreach ($adlar as $ad) {
                        $w->orWhere('hizmetler.hizmet_adi', 'like', '%'.$ad.'%');
                    }
                });
                $durumlar = [];
                $degerler = [];
                foreach ($adlar as $i => $ad) {
                    $durumlar[] = 'WHEN hizmetler.hizmet_adi LIKE ? THEN '.($i + 1);
                    $degerler[] = '%'.$ad.'%';
                }
                $q->orderByRaw('CASE '.implode(' ', $durumlar).' ELSE 9999 END', $degerler);
            }

            return $q->first();
        };

        $r = $adlar ? $sorgu(true) : null;
        if (! $r && $tumVucut) {
            $r = $sorgu(false);
        }

        return $r ? (int) $r->adisyon_id : null;
    }

    /** Randevu paket seansina bagliysa seansin adisyonu. */
    private function seansAdisyonu(object $randevu): ?int
    {
        if (empty($randevu->seans_id)) {
            return null;
        }
        $id = DB::table('adisyon_paket_seanslar as s')->join('adisyon_paketler as p', 'p.id', '=', 's.adisyon_paket_id')
            ->where('s.id', $randevu->seans_id)->value('p.adisyon_id');

        return $id ? (int) $id : null;
    }

    private function tamamenOdendi(int $adisyonId): bool
    {
        $toplam = 0.0;
        $tahsilat = 0.0;
        foreach ([['adisyon_hizmetler', 'tahsilat_hizmetler', 'adisyon_hizmet_id'], ['adisyon_urunler', 'tahsilat_urunler', 'adisyon_urun_id'], ['adisyon_paketler', 'tahsilat_paketler', 'adisyon_paket_id']] as [$kalem, $odeme, $fk]) {
            $idler = DB::table($kalem)->where('adisyon_id', $adisyonId)->pluck('id');
            $toplam += (float) DB::table($kalem)->whereIn('id', $idler)->sum('fiyat');
            $tahsilat += (float) DB::table($odeme)->whereIn($fk, $idler)->sum('tutar');
        }

        return Hesap::tamamenOdenmisMi($toplam, $tahsilat);
    }

    private function kismiOdendi(int $adisyonId): bool
    {
        foreach ([['tahsilat_hizmetler', 'adisyon_hizmetler', 'adisyon_hizmet_id'], ['tahsilat_paketler', 'adisyon_paketler', 'adisyon_paket_id'], ['tahsilat_urunler', 'adisyon_urunler', 'adisyon_urun_id']] as [$odeme, $kalem, $fk]) {
            if (DB::table($odeme)->join($kalem, $kalem.'.id', '=', $odeme.'.'.$fk)->where($kalem.'.adisyon_id', $adisyonId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Ayni tarihli baska randevunun bugun acilmis, hic tahsilat almamis adisyonu (urun tahsilati eski kodda da bakilmaz). */
    private function ayniGunAdisyonu(object $randevu, int $salonId): ?int
    {
        $id = DB::table('adisyonlar')
            ->join('adisyon_hizmetler', 'adisyon_hizmetler.adisyon_id', '=', 'adisyonlar.id')
            ->join('randevular', 'randevular.id', '=', 'adisyon_hizmetler.randevu_id')
            ->where('adisyonlar.user_id', $randevu->user_id)->where('adisyonlar.salon_id', $salonId)
            ->whereDate('adisyonlar.tarih', date('Y-m-d'))
            ->where('randevular.tarih', $randevu->tarih)->where('randevular.id', '!=', $randevu->id)
            ->whereNotNull('adisyon_hizmetler.randevu_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tahsilat_hizmetler')
                ->join('adisyon_hizmetler as ah2', 'ah2.id', '=', 'tahsilat_hizmetler.adisyon_hizmet_id')->whereColumn('ah2.adisyon_id', 'adisyonlar.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tahsilat_paketler')
                ->join('adisyon_paketler as ap2', 'ap2.id', '=', 'tahsilat_paketler.adisyon_paket_id')->whereColumn('ap2.adisyon_id', 'adisyonlar.id'))
            ->value('adisyonlar.id');

        return $id ? (int) $id : null;
    }

    private function yeniAdisyon(object $randevu, int $salonId, int $yetkiliId): int
    {
        return (int) Adisyonlar::ac($salonId, (int) $randevu->user_id, $yetkiliId, date('Y-m-d'))->id;
    }

    /** Yardimci personel ve paket seansi atlanir; (randevu, hizmet, personel) sayisina gore eksik olan eklenir. */
    private function hizmetleriEkle(object $randevu, $hizmetler, int $adisyonId, int $salonId): void
    {
        foreach ($hizmetler as $h) {
            if ($h->yardimci_personel) {
                continue;
            }
            if (DB::table('adisyon_paket_seanslar')->where('randevu_id', $randevu->id)->where('hizmet_id', $h->hizmet_id)->exists()) {
                continue;
            }
            $mevcut = DB::table('adisyon_hizmetler')->where('randevu_id', $randevu->id)->where('hizmet_id', $h->hizmet_id)
                ->where('personel_id', $h->personel_id)->count();
            $gerekli = $hizmetler->filter(fn ($x) => $x->hizmet_id == $h->hizmet_id && $x->personel_id == $h->personel_id && ! $x->yardimci_personel)->count();
            if ($mevcut >= $gerekli) {
                continue;
            }
            $this->yazici->hizmetEkle($salonId, $adisyonId, [
                'hizmet_id' => $h->hizmet_id, 'islem_tarihi' => $randevu->tarih, 'islem_saati' => $h->saat, 'sure' => $h->sure_dk, 'fiyat' => $h->fiyat,
                'geldi' => true, 'personel_id' => $h->personel_id, 'cihaz_id' => $h->cihaz_id, 'randevu_id' => $randevu->id,
            ]);
        }
    }
}
