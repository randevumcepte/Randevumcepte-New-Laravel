<?php

namespace App\Services\Ozet;

use App\Domain\Ozet\OzetHesap;
use App\Domain\Rapor\OdemeKovasi;
use App\Domain\Rapor\RaporDonemi;
use App\Domain\Satis\KalemTuru;
use App\Models\Randevular;
use App\Services\Rapor\IsletmeRaporServisi;
use App\Services\Rapor\SatisKalemSorgusu;
use App\Services\Tahsilat\AlacakServisi;
use Illuminate\Support\Facades\DB;

/**
 * Ozet (dashboard) SAYISAL kartlari — web /v2/ozet ve Flutter ana ekrani AYNI uclar.
 *
 * Kanonik kurallar (v2 rapor kararlariyla ayni, kendi SUM'i YOK):
 *   - Ciro/kasa  : tahsilatlar.tutar, odeme_tarihi donemde, odeme yontemi ID kovasi
 *                  (IsletmeRaporServisi::tahsilatKovalari — Isletme Raporlari ile ayni rakam)
 *   - Personel   : "kendi ciro" kapsami = kendi kalemlerinden gelen tahsilatlar
 *                  (SatisKalemSorgusu::personelTahsilatIdleri)
 *   - Randevu    : IsletmeRaporServisi::randevuDurumlari (kapali saat bloklari haric)
 *   - Satis say. : satis tarihi (SatisKalemSorgusu::donemFiltresi), seansli hizmet = paket
 *   - Alacak     : AlacakServisi::acikSorgu (odenmis vade kalintisi sayilmaz)
 * Hicbir sey YAZMAZ.
 */
class OzetMetrikServisi
{
    public function __construct(private IsletmeRaporServisi $rapor) {}

    /**
     * "Bugunun ozeti" sayaclari (web hero + mobil gunluk grid). $personelId: rol 5 kendi kayitlari.
     * Anahtar null ise cagiranin yetkisi yok demektir (bkz. OzetAcilisServisi).
     */
    public function bugun(int $salonId, ?int $personelId, bool $randevuGor, bool $satisGor): array
    {
        $gun = RaporDonemi::coz('bugun', null, null);
        $randevu = fn () => $this->rapor->randevuSorgusu($salonId, $gun, 'r', true)->whereIn('r.durum', [Randevular::BEKLENIYOR, Randevular::ONAYLANDI])
            ->when($personelId, fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('randevu_hizmetler as rk')
                ->whereColumn('rk.randevu_id', 'r.id')->where('rk.personel_id', $personelId)));

        return [
            'randevu'     => $randevuGor ? $randevu()->count() : null,
            'on_gorusme'  => $randevuGor ? $randevu()->whereNotNull('r.on_gorusme_id')->count() : null,
            'urun_satis'  => $satisGor ? $this->satisSayisi($salonId, $gun, $personelId, [KalemTuru::Urun], false) : null,
            'paket_satis' => $satisGor ? $this->satisSayisi($salonId, $gun, $personelId, [KalemTuru::Paket, KalemTuru::Hizmet], true) : null,
        ];
    }

    /** Kasa karti: odeme yontemi kovalari + toplam + son 7 gun cizgisi. */
    public function kasa(int $salonId, RaporDonemi $d, ?int $personelId): array
    {
        $ids = $personelId ? SatisKalemSorgusu::personelTahsilatIdleri($personelId) : null;
        $kova = $this->rapor->tahsilatKovalari($salonId, $d, $ids);

        $yedi = RaporDonemi::coz('son7', null, null);
        $gunluk = DB::table('tahsilatlar')->where('salon_id', $salonId)
            ->whereBetween('odeme_tarihi', [$yedi->basSaat(), $yedi->bitSaat()])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->groupByRaw('DATE(odeme_tarihi)')
            ->selectRaw('DATE(odeme_tarihi) as gun, SUM(tutar) as toplam')
            ->pluck('toplam', 'gun');

        $cizgi = [];
        for ($t = new \DateTimeImmutable($yedi->baslangic); $t->format('Y-m-d') <= $yedi->bitis; $t = $t->modify('+1 day')) {
            $g = $t->format('Y-m-d');
            $cizgi[] = ['tarih' => $g, 'tutar' => round((float) ($gunluk[$g] ?? 0), 2)];
        }

        return [
            'kovalar'          => $kova,
            'odeme_etiketleri' => OdemeKovasi::liste($kova),
            'toplam'           => round(array_sum($kova), 2),
            'son7_gun'         => $cizgi,
            'personel_kapsami' => $personelId !== null,
            'donem'            => $d->dizi(),
        ];
    }

    /** Randevu Ayrintilari karti: olusturulan / sonuclanan / sonuclanmayan + doluluk + kaynak. */
    public function randevu(int $salonId, RaporDonemi $d): array
    {
        $durum = $this->rapor->randevuDurumlari($salonId, $d);
        $aralik = DB::table('salonlar')->where('id', $salonId)->value('randevu_araligi');
        $personel = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)->count();

        return [
            'olusturulan'   => $durum['toplam'],
            'sonuclanan'    => $durum['sonuclanan'],
            'sonuclanmayan' => $durum['iptal'] + $durum['gelmedi'],
            'bekleyen'      => $durum['bekleyen'],
            'onayli'        => $durum['onayli'],
            'doluluk'       => OzetHesap::salonDoluluk($durum['toplam'], $personel, $aralik === null ? null : (int) $aralik, $d->gunSayisi()),
            'kaynak'        => $durum['kaynak'],
            'donem'         => $d->dizi(),
        ];
    }

    /** Personel Anlik Durum: bugunku asil randevu sayisi + doluluk (tek gruplu sorgu; eskide N+1). */
    public function personelDurum(int $salonId): array
    {
        $gun = RaporDonemi::coz('bugun', null, null);
        $sayilar = $this->rapor->randevuSorgusu($salonId, $gun)
            ->join('randevu_hizmetler as rh', 'rh.randevu_id', '=', 'r.id')
            ->whereRaw('COALESCE(rh.yardimci_personel,0) != 1')
            ->groupBy('rh.personel_id')
            ->selectRaw('rh.personel_id, COUNT(DISTINCT r.id) as adet')
            ->pluck('adet', 'personel_id');

        $liste = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
            ->where(fn ($q) => $q->where('arsivli', 0)->orWhereNull('arsivli'))
            ->orderBy('takvim_sirasi')->get(['id', 'personel_adi'])
            ->map(fn ($p) => [
                'personel_id' => (int) $p->id,
                'ad'          => trim((string) $p->personel_adi),
                'randevu'     => $n = (int) ($sayilar[$p->id] ?? 0),
                'doluluk'     => OzetHesap::personelDoluluk($n),
            ])->sortByDesc('doluluk')->values()->take(12)->all();

        return ['personeller' => $liste, 'kapasite' => OzetHesap::PERSONEL_GUNLUK_KAPASITE];
    }

    /** Bugunku Akis: bugunun randevulari + tahsilatlari saat sirali (yalniz ilk ad — minimizasyon). */
    public function akis(int $salonId): array
    {
        $gun = RaporDonemi::coz('bugun', null, null);
        $ilkAd = fn ($ad, $vars) => trim(explode(' ', trim((string) $ad))[0]) ?: $vars;

        $randevular = $this->rapor->randevuSorgusu($salonId, $gun, 'r', true)
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->get(['r.id', 'r.saat', 'r.durum', 'r.randevuya_geldi', 'u.name'])
            ->map(fn ($r) => [
                'tip'    => 'randevu',
                'id'     => (int) $r->id,
                'saat'   => substr((string) $r->saat, 0, 5),
                'baslik' => $ilkAd($r->name, 'Randevu'),
                'durum'  => \App\Domain\Randevu\RandevuDurumu::etiket($r->durum, $r->randevuya_geldi),
                'tutar'  => null,
            ]);

        $tahsilatlar = DB::table('tahsilatlar as t')->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->where('t.salon_id', $salonId)->whereBetween('t.odeme_tarihi', [$gun->basSaat(), $gun->bitSaat()])
            ->get(['t.id', 't.odeme_tarihi', 't.tutar', 'u.name'])
            ->map(fn ($t) => [
                'tip'    => 'tahsilat',
                'id'     => (int) $t->id,
                'saat'   => substr((string) $t->odeme_tarihi, 11, 5) ?: '00:00',
                'baslik' => $ilkAd($t->name, 'Müşteri'),
                'durum'  => null,
                'tutar'  => round((float) $t->tutar, 2),
            ]);

        return [
            'liste' => $randevular->concat($tahsilatlar)->sortBy('saat')->values()->all(),
            'simdi' => date('H:i'),
        ];
    }

    /**
     * Donem karsilastirmasi (mobil performans kartlari): simdi / onceki ciro + degisim %,
     * 7 donemlik seri, acik alacak, liderler (personel / hizmet / urun), saat dagilimi.
     * v1 dashboardKarsilastirma'nin karsiligi — ciro artik Kasa karti ile AYNI kaynak.
     */
    public function karsilastirma(int $salonId, RaporDonemi $d, ?int $personelId, bool $liderler): array
    {
        $ids = $personelId ? SatisKalemSorgusu::personelTahsilatIdleri($personelId) : null;
        $ciro = fn (RaporDonemi $x) => round(array_sum($this->rapor->tahsilatKovalari($salonId, $x, $ids)), 2);

        $onceki = $d->kaydir(-1);
        $simdi = $ciro($d);
        $oncekiCiro = $ciro($onceki);

        $seri = [];
        for ($i = 6; $i >= 0; $i--) {
            $x = $i === 0 ? $d : $d->kaydir(-$i);
            $seri[] = ['baslangic' => $x->baslangic, 'bitis' => $x->bitis, 'tutar' => $i === 0 ? $simdi : ($i === 1 ? $oncekiCiro : $ciro($x))];
        }

        $alacak = AlacakServisi::acikSorgu($salonId)
            ->whereBetween('alacaklar.planlanan_odeme_tarihi', [$d->baslangic, $d->bitis])
            ->when($personelId, fn ($q) => $q->whereExists(function ($e) use ($personelId) {
                $e->selectRaw('1')->from('adisyonlar as a')->whereColumn('a.id', 'alacaklar.adisyon_id')
                    ->where(function ($w) use ($personelId) {
                        foreach (KalemTuru::cases() as $tur) {
                            $w->orWhereExists(fn ($k) => $k->selectRaw('1')->from($tur->kalemTablosu().' as k')
                                ->whereColumn('k.adisyon_id', 'a.id')->where('k.personel_id', $personelId));
                        }
                    });
            }))
            ->sum('alacaklar.tutar');

        $randevu = $this->rapor->randevular($salonId, $d);
        $enYogun = collect($randevu['saat_dagilim'])->sortByDesc('adet')->first();

        return [
            'simdi'        => ['etiket' => $d->dizi()['etiket'], 'tutar' => $simdi],
            'onceki'       => ['etiket' => $d->oncekiEtiket(), 'tutar' => $oncekiCiro, 'baslangic' => $onceki->baslangic, 'bitis' => $onceki->bitis],
            'degisim'      => OzetHesap::degisim($simdi, $oncekiCiro),
            'seri'         => $seri,
            'alacak'       => round((float) $alacak, 2),
            'liderler'     => $liderler ? $this->liderler($salonId, $d) : null,
            'saat_dagilim' => $randevu['saat_dagilim'],
            'en_yogun_saat' => $enYogun && $enYogun['adet'] > 0 ? $enYogun['saat'] : null,
            'personel_kapsami' => $personelId !== null,
            'donem'        => $d->dizi(),
        ];
    }

    /** En cok kazandiran personel (net satis) + en cok uygulanan hizmet / satilan urun (adet). */
    private function liderler(int $salonId, RaporDonemi $d): array
    {
        $ilk = fn (array $l, string $alan) => collect($l)->sortByDesc($alan)->first();
        $p = $ilk($this->rapor->personeller($salonId, $d)['personeller'], 'ciro');
        $h = $ilk($this->rapor->kalemler($salonId, $d, KalemTuru::Hizmet)['kalemler'], 'adet');
        $u = $ilk($this->rapor->kalemler($salonId, $d, KalemTuru::Urun)['kalemler'], 'adet');

        return [
            'personel' => $p ? ['ad' => $p['personel_adi'], 'tutar' => $p['ciro']] : null,
            'hizmet'   => $h ? ['ad' => $h['ad'], 'adet' => $h['adet']] : null,
            'urun'     => $u ? ['ad' => $u['ad'], 'adet' => $u['adet']] : null,
        ];
    }

    /**
     * Gunun satis (adisyon) sayisi: verilen kalem turlerinden en az biri olan adisyonlar.
     * $paketMi: hizmet turunde yalniz seansli kalemler sayilir (seansli = paket kovasi).
     *
     * @param  array<KalemTuru>  $turler
     */
    private function satisSayisi(int $salonId, RaporDonemi $d, ?int $personelId, array $turler, bool $paketMi): int
    {
        $q = SatisKalemSorgusu::donemFiltresi(DB::table('adisyonlar as a')->where('a.salon_id', $salonId), $d);

        return $q->where(function ($w) use ($turler, $paketMi, $personelId) {
            foreach ($turler as $tur) {
                $w->orWhereExists(fn ($e) => $e->selectRaw('1')->from($tur->kalemTablosu().' as k')
                    ->whereColumn('k.adisyon_id', 'a.id')
                    ->when($paketMi && $tur === KalemTuru::Hizmet, fn ($x) => $x->whereRaw(KalemTuru::seansliSql('k')))
                    ->when($personelId, fn ($x) => $x->where('k.personel_id', $personelId)));
            }
        })->count();
    }
}
