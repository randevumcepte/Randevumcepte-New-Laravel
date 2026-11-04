<?php

namespace App\Services\Randevu;

use App\Domain\Randevu\TahsilatKalemSecimi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Adisyonlar;
use App\Models\Randevular;
use App\Services\Satis\KalemServisi;
use App\Support\AktiviteLog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Randevudan tahsilata git" — randevunun adisyonunu bulur ya da acar, kalemlerini
 * ekler; istemci donen adisyonun tahsilat ekranina gider (web + mobil tek uc).
 *
 * Eski randevuEventDetay (cozumleme) + tahsilataGitRandevudan (adisyon varsa) +
 * randevutahsilet (adisyon yoksa) akislarinin birlesimi. Cozumleme sirasi:
 *   1) adisyon_hizmetler.randevu_id  2) ayni musteri+hizmet+gun satisi
 *   3) seans → SADECE adisyon_paket_id. Seansin adisyon_hizmet_id dali KULLANILMAZ
 *   (alakasiz eski adisyona goturdugu icin eskide de kaldirildi).
 *
 * Eskiden BILINCLI farklar:
 *   - Paket adisyonu aramasi hizmet_id ile (eskide web'de filtresiz, mobilde LIKE ad).
 *   - Ekstra modunda bu randevuya ait zaten adisyonda olan hizmet yeniden eklenmez
 *     (eskide her tiklamada yeni adisyon acilabiliyordu); randevu satiri kilitlenir.
 */
class RandevuTahsilatServisi
{
    public function __construct(private KalemServisi $kalemler) {}

    public function tahsilataGec(int $randevuId, ?int $yetkiliId, bool $yaz): array
    {
        if (! $yaz) {
            $randevu = $this->randevu($randevuId);
            $plan = $this->planla($randevu);

            return ['ok' => true, 'yazildi' => false, 'mesaj' => 'DRY-RUN', 'plan' => $this->planOzeti($plan), 'adisyon_id' => $plan['adisyon_id'], 'salon_id' => (int) $randevu->salon_id];
        }

        return DB::transaction(function () use ($randevuId, $yetkiliId) {
            $randevu = $this->randevu($randevuId, true);
            $plan = $this->planla($randevu);
            $salonId = (int) $randevu->salon_id;
            $musteriId = (int) $randevu->user_id;

            $adisyonId = $plan['adisyon_id']
                ?? (int) Adisyonlar::ac($salonId, $musteriId, $yetkiliId, date('Y-m-d'))->id;

            foreach ($plan['kalemler'] as $s) {
                $this->kalemler->randevuKalemiEkle($adisyonId, $salonId, (int) $s['randevu_id'], (string) $s['tarih'], $s['kalem'], $yetkiliId);
            }

            // Mobil uygulama bu bayrakla "tahsilat acildi" durumunu okur (v1 ile ayni).
            $randevu->tahsilat_eklendi = true;
            $randevu->save();

            if ($plan['kalemler']) {
                AktiviteLog::yaz($salonId, $yetkiliId, 'randevu_tahsilat', 'randevu', (int) $randevu->id,
                    DB::table('users')->where('id', $musteriId)->value('name'),
                    'Randevudan tahsilata geçildi (adisyon #'.$adisyonId.')', ['adisyon_id' => $adisyonId, 'kalem' => count($plan['kalemler'])]);
            }

            return [
                'ok'         => true,
                'yazildi'    => true,
                'mesaj'      => 'Tahsilata geçiliyor',
                'plan'       => $this->planOzeti($plan),
                'adisyon_id' => $adisyonId,
                'musteri_id' => $musteriId,
                'salon_id'   => $salonId,
            ];
        });
    }

    private function randevu(int $id, bool $kilit = false): Randevular
    {
        $q = Randevular::where('id', $id);
        $randevu = ($kilit ? $q->lockForUpdate() : $q)->first();
        if (! $randevu) {
            throw new BulunamadiException('Randevu bulunamadı');
        }
        if ((int) $randevu->user_id === Randevular::KAPALI_SAAT_USER_ID) {
            throw new GecersizIslemException('Kapalı saatte tahsilat yapılamaz.');
        }
        if ($randevu->on_gorusme_id !== null) {
            throw new GecersizIslemException('Ön görüşme randevusunun tahsilatı satış üzerinden yapılır.');
        }
        if ((int) $randevu->durum !== 1) {
            throw new GecersizIslemException('Tahsilat yalnızca onaylı randevuda yapılabilir.');
        }

        return $randevu;
    }

    /** @return array{adisyon_id:?int,kaynak:string,kalemler:list<array{randevu_id:int,tarih:string,kalem:object}>} */
    private function planla(Randevular $randevu): array
    {
        $kalemler = DB::table('randevu_hizmetler')->where('randevu_id', $randevu->id)->orderBy('id')->get()->all();
        $satir = fn (array $liste) => array_map(fn ($k) => ['randevu_id' => (int) $randevu->id, 'tarih' => (string) $randevu->tarih, 'kalem' => $k], $liste);

        $mevcut = $this->adisyonCoz($randevu, $kalemler);
        if ($mevcut) {
            // Adisyon var: yalniz gercek ekstra varsa YENI adisyona (eskiyle ayni), yoksa adisyonun kendisine.
            $ekstra = TahsilatKalemSecimi::eklenecekler($kalemler, $this->haricHizmetler((int) $randevu->id), true, $this->adisyonHizmetleri($mevcut));

            return $ekstra
                ? ['adisyon_id' => null, 'kaynak' => 'ekstra_yeni_adisyon', 'kalemler' => $satir($ekstra)]
                : ['adisyon_id' => $mevcut, 'kaynak' => 'mevcut_adisyon', 'kalemler' => []];
        }

        $eklenecek = $satir(TahsilatKalemSecimi::eklenecekler($kalemler, $this->haricHizmetler((int) $randevu->id)));
        // Ayni gun ayni musterinin diger randevulari da tek adisyonda toplanir (paketsiz olanlar).
        $tumu = array_merge($eklenecek, $this->ayniGunKalemleri($randevu));

        $paket = $this->paketAdisyonu($randevu, $kalemler);
        if ($paket && $this->odemesiz(DB::table('adisyonlar')->where('adisyonlar.id', $paket))->exists()) {
            return ['adisyon_id' => $paket, 'kaynak' => 'paket_adisyonu', 'kalemler' => $tumu];
        }
        if (! $tumu) {
            throw new GecersizIslemException('Bu randevuda tahsil edilecek kalem yok.');
        }
        // Paket adisyonunda kismi odeme varsa eskide de yeni adisyon acilirdi (birlestirme denenmez).
        if ($paket) {
            return ['adisyon_id' => null, 'kaynak' => 'yeni_adisyon', 'kalemler' => $tumu];
        }

        $birlesecek = $this->ayniGunAdisyonu($randevu);

        return ['adisyon_id' => $birlesecek, 'kaynak' => $birlesecek ? 'ayni_gun_adisyonu' : 'yeni_adisyon', 'kalemler' => $tumu];
    }

    /** Eski randevuEventDetay cozumleme sirasi (sinif basligi). */
    private function adisyonCoz(Randevular $randevu, array $kalemler): ?int
    {
        $id = DB::table('adisyon_hizmetler')->where('randevu_id', $randevu->id)->value('adisyon_id');

        $hizmetIdler = array_values(array_filter(array_map(fn ($k) => (int) $k->hizmet_id, $kalemler)));
        if (! $id && $hizmetIdler) {
            $id = DB::table('adisyon_hizmetler as ah')->join('adisyonlar as a', 'a.id', '=', 'ah.adisyon_id')
                ->where('a.user_id', $randevu->user_id)->where('a.salon_id', $randevu->salon_id)
                ->whereIn('ah.hizmet_id', $hizmetIdler)
                ->whereDate('ah.islem_tarihi', $randevu->tarih)
                ->orderByDesc('ah.id')->value('ah.adisyon_id');
        }
        if (! $id) {
            $id = DB::table('adisyon_paket_seanslar as s')->join('adisyon_paketler as ap', 'ap.id', '=', 's.adisyon_paket_id')
                ->where('s.randevu_id', $randevu->id)->value('ap.adisyon_id');
        }

        return $id ? (int) $id : null;
    }

    /** Musterinin bu randevu hizmetlerini iceren, kullanilmamis seansi kalan paketinin adisyonu. */
    private function paketAdisyonu(Randevular $randevu, array $kalemler): ?int
    {
        $hizmetIdler = array_values(array_filter(array_map(fn ($k) => (int) $k->hizmet_id, $kalemler)));
        if (! $hizmetIdler) {
            return null;
        }
        $id = DB::table('adisyonlar as a')->join('adisyon_paketler as ap', 'ap.adisyon_id', '=', 'a.id')
            ->join('paket_hizmetler as ph', 'ph.paket_id', '=', 'ap.paket_id')
            ->join('adisyon_paket_seanslar as s', 's.adisyon_paket_id', '=', 'ap.id')
            ->where('a.salon_id', $randevu->salon_id)->where('a.user_id', $randevu->user_id)
            ->whereIn('ph.hizmet_id', $hizmetIdler)->whereNull('s.geldi')
            ->orderByDesc('a.id')->value('a.id');

        return $id ? (int) $id : null;
    }

    /** Bugun acilmis, ayni gunun baska randevusuna bagli, hic odeme almamis adisyon. */
    private function ayniGunAdisyonu(Randevular $randevu): ?int
    {
        $q = DB::table('adisyonlar')
            ->join('adisyon_hizmetler as ah', 'ah.adisyon_id', '=', 'adisyonlar.id')
            ->join('randevular as r', 'r.id', '=', 'ah.randevu_id')
            ->where('adisyonlar.user_id', $randevu->user_id)->where('adisyonlar.salon_id', $randevu->salon_id)
            ->whereDate('adisyonlar.tarih', date('Y-m-d'))
            ->where('r.tarih', $randevu->tarih)->where('r.id', '!=', $randevu->id);
        $id = $this->odemesiz($q)->value('adisyonlar.id');

        return $id ? (int) $id : null;
    }

    /** Hic tahsilat girisi olmayan adisyonlar — TEK kural (eski _adisyonKismiOdenmisMi'nin tersi). */
    private function odemesiz(Builder $q): Builder
    {
        return $q
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('tahsilat_hizmetler as th')
                ->join('adisyon_hizmetler as ah2', 'ah2.id', '=', 'th.adisyon_hizmet_id')
                ->whereColumn('ah2.adisyon_id', 'adisyonlar.id'))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('tahsilat_paketler as tp')
                ->join('adisyon_paketler as ap2', 'ap2.id', '=', 'tp.adisyon_paket_id')
                ->whereColumn('ap2.adisyon_id', 'adisyonlar.id'));
    }

    /** Ayni gun ayni musterinin iptal olmayan diger randevularinin eklenecek kalemleri. */
    private function ayniGunKalemleri(Randevular $randevu): array
    {
        $digerleri = Randevular::where('user_id', $randevu->user_id)->where('salon_id', $randevu->salon_id)
            ->where('tarih', $randevu->tarih)->where('id', '!=', $randevu->id)->where('durum', '<', 2)->get(['id', 'tarih']);

        $sonuc = [];
        foreach ($digerleri as $d) {
            $kalemler = DB::table('randevu_hizmetler')->where('randevu_id', $d->id)->orderBy('id')->get()->all();
            foreach (TahsilatKalemSecimi::eklenecekler($kalemler, $this->haricHizmetler((int) $d->id)) as $k) {
                $sonuc[] = ['randevu_id' => (int) $d->id, 'tarih' => (string) $d->tarih, 'kalem' => $k];
            }
        }

        return $sonuc;
    }

    /** Randevuda paketten dusen ya da adisyona zaten eklenmis hizmetler. @return list<int> */
    private function haricHizmetler(int $randevuId): array
    {
        return DB::table('adisyon_paket_seanslar')->where('randevu_id', $randevuId)->pluck('hizmet_id')
            ->merge(DB::table('adisyon_hizmetler')->where('randevu_id', $randevuId)->pluck('hizmet_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Adisyondaki dogrudan hizmetler + adisyonun paketlerinin icerigi. @return list<int> */
    private function adisyonHizmetleri(int $adisyonId): array
    {
        return DB::table('adisyon_hizmetler')->where('adisyon_id', $adisyonId)->pluck('hizmet_id')
            ->merge(DB::table('adisyon_paketler as ap')->join('paket_hizmetler as ph', 'ph.paket_id', '=', 'ap.paket_id')
                ->where('ap.adisyon_id', $adisyonId)->pluck('ph.hizmet_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function planOzeti(array $plan): array
    {
        return [
            'kaynak'   => $plan['kaynak'],
            'kalemler' => array_map(fn ($s) => [
                'randevu_id' => $s['randevu_id'],
                'hizmet_id'  => (int) $s['kalem']->hizmet_id,
                'fiyat'      => $s['kalem']->fiyat,
            ], $plan['kalemler']),
        ];
    }
}
