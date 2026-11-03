<?php

namespace App\Services\Rapor;

use App\Domain\Rapor\OdemeKovasi;
use App\Domain\Rapor\RaporDonemi;
use App\Domain\Satis\KalemTuru;
use App\Models\Randevular;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Isletme Raporlari ("Performans Panosu": Isletme / Hizmet / Urun / Personel / Musteri /
 * Randevu sekmeleri) — eski StoreAdminController::isletmeRaporlari* uclarinin v2 karsiligi.
 *
 * Satis rakamlari SatisKalemSorgusu'ndan (Satis Raporlari ile AYNI kaynak → iki ekran
 * birbirini tutar). Eski kodun duzeltilen farklari: toplamlar ilk-20 satirdan degil tum
 * kumeden; satis tarihi created_at yerine satis tarihi; seansli hizmet paket kovasinda;
 * randevu durum kartlarina "onayli, sonuclanmamis" eklendi (toplam = kartlar toplami);
 * iptal randevular personel/musteri aktivitesine sayilmaz; tekrar gelen = donemde yeni
 * KAYIT olmayan aktif musteri; cinsiyet 0/1 kodlamasi taniniyor; odeme kovasi id esasli;
 * 60 dk bayat onbellek yok. Hicbir sey YAZMAZ.
 */
class IsletmeRaporServisi
{
    private const IPTAL = [Randevular::IPTAL_EDILDI, Randevular::KABUL_EDILMEDI];

    public function __construct(private SatisKalemSorgusu $sorgu) {}

    /** Isletme sekmesi: sayac kartlari + donem tahsilati (odeme yontemi kovalari) + net satis. */
    public function ozet(int $salonId, RaporDonemi $d): array
    {
        $kalemler = $this->sorgu->kalemBazli($salonId, $d);
        $topla = fn (callable $f, string $alan) => array_sum(array_map(fn ($k) => $k[$alan], array_filter($kalemler, $f)));

        $adisyon = SatisKalemSorgusu::donemFiltresi(DB::table('adisyonlar as a')->where('a.salon_id', $salonId), $d)->count();
        $randevu = $this->randevuSorgusu($salonId, $d, 'r', true)->count(); // Randevu sekmesi "Toplam" ile ayni

        $kova = $this->tahsilatKovalari($salonId, $d);

        return [
            'toplam_randevu'   => $randevu,
            'toplam_adisyon'   => $adisyon,
            'satilan_urun'     => (int) $topla(fn ($k) => $k['tur'] === 'urun', 'adet'),
            'uygulanan_hizmet' => (int) $topla(fn ($k) => $k['tur'] === 'hizmet' && ! $k['seansli'], 'adet'),
            'satilan_paket'    => (int) $topla(fn ($k) => $k['tur'] === 'paket' || $k['seansli'], 'adet'),
            'satis_net'        => round($topla(fn () => true, 'net'), 2),
            'toplam_gelir'     => round(array_sum($kova), 2),
            'odeme_yontemleri' => $kova,
            'odeme_etiketleri' => OdemeKovasi::liste(),
            'donem'            => $d->dizi(),
        ];
    }

    /** Hizmet / Urun sekmesi: tum kalemler ciroya gore + dogru toplamlar. */
    public function kalemler(int $salonId, RaporDonemi $d, KalemTuru $tur): array
    {
        $liste = [];
        foreach ($this->sorgu->kalemBazli($salonId, $d) as $k) {
            if ($k['tur'] === $tur->value && ! $k['seansli']) {
                $liste[] = ['kalem_id' => $k['kalem_id'], 'ad' => $k['ad'], 'adet' => $k['adet'], 'ciro' => $k['net']];
            }
        }
        usort($liste, fn ($a, $b) => $b['ciro'] <=> $a['ciro']);

        $adet = array_sum(array_column($liste, 'adet'));
        $ciro = round(array_sum(array_column($liste, 'ciro')), 2);

        return [
            'toplam_adet'    => $adet,
            'toplam_ciro'    => $ciro,
            'ortalama_fiyat' => $adet > 0 ? round($ciro / $adet, 2) : 0.0,
            'cesit'          => count($liste),
            'kalemler'       => $liste,
            'donem'          => $d->dizi(),
        ];
    }

    /** Personel sekmesi: net satis cirosu (hizmet/urun/paket) + hizmet ve randevu sayisi. */
    public function personeller(int $salonId, RaporDonemi $d): array
    {
        $satir = [];
        foreach ($this->sorgu->personelKalemBazli($salonId, $d) as $k) {
            $s = &$satir[$k['personel_id']];
            $kova = $k['seansli'] ? 'paket' : $k['tur'];
            $s[$kova.'_ciro'] = round(($s[$kova.'_ciro'] ?? 0) + $k['net'], 2);
            if ($kova === 'hizmet') {
                $s['hizmet_say'] = ($s['hizmet_say'] ?? 0) + $k['adet'];
            }
            unset($s);
        }

        // Asil personel (yardimci haric — NULL kalemler elenmesin), iptal randevular haric.
        $randevular = $this->randevuSorgusu($salonId, $d, 'r')
            ->join('randevu_hizmetler as rh', 'rh.randevu_id', '=', 'r.id')
            ->whereNotNull('rh.personel_id')
            ->whereRaw('COALESCE(rh.yardimci_personel,0) != 1')
            ->groupBy('rh.personel_id')
            ->selectRaw('rh.personel_id, COUNT(DISTINCT r.id) as adet')
            ->pluck('adet', 'personel_id');

        $sonuc = [];
        $personeller = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
            ->where(fn ($q) => $q->where('arsivli', 0)->orWhereNull('arsivli'))
            ->orderBy('takvim_sirasi')->get(['id', 'personel_adi']);
        foreach ($personeller as $p) {
            $s = $satir[$p->id] ?? [];
            $r = [
                'personel_id' => (int) $p->id,
                'personel_adi' => trim((string) $p->personel_adi),
                'randevu_say' => (int) ($randevular[$p->id] ?? 0),
                'hizmet_say'  => (int) ($s['hizmet_say'] ?? 0),
                'hizmet_ciro' => (float) ($s['hizmet_ciro'] ?? 0),
                'urun_ciro'   => (float) ($s['urun_ciro'] ?? 0),
                'paket_ciro'  => (float) ($s['paket_ciro'] ?? 0),
            ];
            $r['ciro'] = round($r['hizmet_ciro'] + $r['urun_ciro'] + $r['paket_ciro'], 2);
            if ($r['randevu_say'] || $r['hizmet_say'] || $r['ciro']) {
                $sonuc[] = $r;
            }
        }
        usort($sonuc, fn ($a, $b) => $b['ciro'] <=> $a['ciro']);

        return ['personeller' => $sonuc, 'toplam_ciro' => round(array_sum(array_column($sonuc, 'ciro')), 2), 'donem' => $d->dizi()];
    }

    /** Musteri sekmesi: aktif / yeni / tekrar gelen, cinsiyet, en cok harcayan 10 musteri. */
    public function musteriler(int $salonId, RaporDonemi $d, bool $telGor): array
    {
        $aktif = $this->randevuSorgusu($salonId, $d, 'r')->distinct()->select('r.user_id');
        $toplamAktif = DB::query()->fromSub($aktif, 'x')->count();

        $yeniKayit = DB::table('musteri_portfoy')->where('salon_id', $salonId)
            ->whereBetween('created_at', [$d->basSaat(), $d->bitSaat()]);
        $yeni = (clone $yeniKayit)->count();
        $aktifYeni = (clone $yeniKayit)->whereIn('user_id', $aktif)->distinct()->count('user_id');

        $cinsiyet = ['kadin' => 0, 'erkek' => 0, 'belirsiz' => 0];
        foreach (DB::table('users')->whereIn('id', $aktif)->groupBy('cinsiyet')->selectRaw('cinsiyet, COUNT(*) as adet')->get() as $c) {
            $cinsiyet[self::cinsiyet($c->cinsiyet)] += (int) $c->adet;
        }

        $enCok = DB::table('tahsilatlar as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.salon_id', $salonId)
            ->whereBetween('t.odeme_tarihi', [$d->basSaat(), $d->bitSaat()])
            ->groupBy('u.id', 'u.name', 'u.cep_telefon')
            ->orderByDesc('toplam')->limit(10)
            ->get(['u.id', 'u.name', 'u.cep_telefon', DB::raw('SUM(t.tutar) as toplam'), DB::raw('COUNT(t.id) as islem_say')])
            ->map(fn ($m) => [
                'musteri_id' => (int) $m->id,
                'ad'         => $m->name,
                'telefon'    => $m->cep_telefon ? ($telGor ? $m->cep_telefon : YetkiSabitleri::telefonMaskele($m->cep_telefon)) : null,
                'islem_say'  => (int) $m->islem_say,
                'toplam'     => round((float) $m->toplam, 2),
            ])->all();

        $tekrar = max(0, $toplamAktif - $aktifYeni);

        return [
            'toplam_aktif'   => $toplamAktif,
            'yeni_musteri'   => $yeni,
            'tekrar_gelen'   => $tekrar,
            'sadakat_yuzde'  => $toplamAktif > 0 ? (int) round($tekrar / $toplamAktif * 100) : 0,
            'cinsiyet'       => $cinsiyet,
            'top_musteriler' => $enCok,
            'donem'          => $d->dizi(),
        ];
    }

    /** Randevu sekmesi: durum kartlari + kaynak + saatlik / gunluk dagilim. */
    public function randevular(int $salonId, RaporDonemi $d): array
    {
        $durum = $this->randevuDurumlari($salonId, $d);

        $saat = $this->randevuSorgusu($salonId, $d, 'r', true)->groupByRaw('HOUR(r.saat)')
            ->selectRaw('HOUR(r.saat) as h, COUNT(*) as adet')->pluck('adet', 'h');
        $gun = $this->randevuSorgusu($salonId, $d, 'r', true)->groupByRaw('DAYOFWEEK(r.tarih)')
            ->selectRaw('DAYOFWEEK(r.tarih) as g, COUNT(*) as adet')->pluck('adet', 'g');

        $gunler = ['Pts' => 2, 'Sal' => 3, 'Çar' => 4, 'Per' => 5, 'Cum' => 6, 'Cts' => 7, 'Paz' => 1];

        return $durum + [
            'saat_dagilim' => array_map(fn ($h) => ['saat' => str_pad((string) $h, 2, '0', STR_PAD_LEFT), 'adet' => (int) ($saat[$h] ?? 0)], range(0, 23)),
            'gun_dagilim'  => array_map(fn ($ad, $no) => ['gun' => $ad, 'adet' => (int) ($gun[$no] ?? 0)], array_keys($gunler), $gunler),
            'donem'        => $d->dizi(),
        ];
    }

    /**
     * Donem randevu durum kartlari + olusturma kaynagi (TEK toplama sorgusu) — Isletme Raporlari
     * Randevu sekmesi ve Ozet "Randevu Ayrintilari" karti AYNI sayimi kullanir.
     * toplam = sonuclanan + gelmedi + onayli + iptal + bekleyen (kapali saat bloklari haric).
     */
    public function randevuDurumlari(int $salonId, RaporDonemi $d): array
    {
        $onay = Randevular::ONAYLANDI;
        $iptal = implode(',', self::IPTAL);
        $k = $this->randevuSorgusu($salonId, $d, 'r', true)->selectRaw("
            COUNT(*) as toplam,
            SUM(r.durum = {$onay} AND r.randevuya_geldi = 1) as sonuclanan,
            SUM(r.durum = {$onay} AND r.randevuya_geldi = 0) as gelmedi,
            SUM(r.durum = {$onay} AND r.randevuya_geldi IS NULL) as onayli,
            SUM(r.durum IN ({$iptal})) as iptal,
            SUM(r.durum = ".Randevular::BEKLENIYOR.') as bekleyen,
            SUM(r.salon = 1) as isletme, SUM(r.web = 1) as web, SUM(r.uygulama = 1) as uygulama')->first();

        return [
            'toplam'     => (int) $k->toplam,
            'sonuclanan' => (int) $k->sonuclanan,
            'gelmedi'    => (int) $k->gelmedi,
            'onayli'     => (int) $k->onayli,
            'iptal'      => (int) $k->iptal,
            'bekleyen'   => (int) $k->bekleyen,
            'kaynak'     => ['isletme' => (int) $k->isletme, 'web' => (int) $k->web, 'uygulama' => (int) $k->uygulama],
        ];
    }

    /**
     * Donem tahsilati odeme yontemi kovalarina (id esasli) — Isletme ozeti + Ozet kasa karti.
     * $tahsilatIdleri: personel kapsami icin alt sorgu (yalniz o tahsilatlar); null = tumu.
     *
     * @return array<string,float>
     */
    public function tahsilatKovalari(int $salonId, RaporDonemi $d, $tahsilatIdleri = null): array
    {
        return OdemeKovasi::topla(DB::table('tahsilatlar')
            ->where('salon_id', $salonId)
            ->whereBetween('odeme_tarihi', [$d->basSaat(), $d->bitSaat()])
            ->when($tahsilatIdleri, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->groupBy('odeme_yontemi_id')
            ->selectRaw('odeme_yontemi_id, SUM(tutar) as toplam')
            ->get());
    }

    /** Salonun donemdeki gercek randevulari (kapali saat bloklari haric; istenirse iptal dahil) — Ozet de kullanir. */
    public function randevuSorgusu(int $salonId, RaporDonemi $d, string $a = 'r', bool $iptalDahil = false)
    {
        return DB::table('randevular as '.$a)
            ->where("{$a}.salon_id", $salonId)
            ->whereBetween("{$a}.tarih", [$d->baslangic, $d->bitis])
            ->where("{$a}.user_id", '!=', Randevular::KAPALI_SAAT_USER_ID)
            ->when(! $iptalDahil, fn ($q) => $q->whereNotIn("{$a}.durum", self::IPTAL));
    }

    /** users.cinsiyet: 0/k/kadın → kadin, 1/e/erkek → erkek (eski kod sayisal kodu tanimiyordu). */
    private static function cinsiyet($v): string
    {
        $v = mb_strtolower(trim((string) $v), 'UTF-8');

        return match (true) {
            $v === '0', $v === 'k', $v === 'f', $v === 'female', str_starts_with($v, 'kad'), str_starts_with($v, 'bayan') => 'kadin',
            $v === '1', $v === 'e', $v === 'm', $v === 'male', str_starts_with($v, 'erk'), str_starts_with($v, 'bay') => 'erkek',
            default => 'belirsiz',
        };
    }
}
