<?php

namespace App\Services\Satis;

use App\Domain\Satis\AdisyonSatir;
use App\Domain\Satis\SatirAksiyon;
use App\Models\Adisyonlar;
use App\Models\Alacaklar;
use App\Models\PersonelPrimOrani;
use App\Models\Salonlar;
use App\Models\User;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * Satis (adisyon) LISTE servisi — TEK KAYNAK (web v2 + Flutter adisyonpage).
 *
 * Eski `ApiController@adisyon_yukle` + `formatAdisyonFast` (ApiController.php:
 * 1243-1805) ile BIREBIR parite. NET=Σ(fiyat−indirim), ODENEN=Σ kalem tahsilat,
 * durum=kalan>0?acik:kapali. Satir matematigi/icerik stringi saf `AdisyonSatir`e
 * delege edilir; bu servis yalnizca sorgu + filtre + kapsam + prim cozumu yapar.
 * Hicbir sey YAZMAZ.
 *
 * Donen cevap, eski `satislar` ucuyle ayni ust-duzey anahtarlar + ayni `data[]`
 * satir sozlesmesini (drop-in) tasir — bkz. §data satiri.
 */
class SatisListeServisi
{
    /** NET (per adisyonlar.id) — opsiyonel personel filtreli ($pf). */
    public static function netSql(string $pf = ''): string
    {
        return '(COALESCE((SELECT SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)) FROM adisyon_hizmetler WHERE adisyon_id=adisyonlar.id'.$pf.'),0)'
            .'+COALESCE((SELECT SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)) FROM adisyon_urunler WHERE adisyon_id=adisyonlar.id'.$pf.'),0)'
            .'+COALESCE((SELECT SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)) FROM adisyon_paketler WHERE adisyon_id=adisyonlar.id'.$pf.'),0))';
    }

    /** İNDİRİM toplamı (per adisyonlar.id) — opsiyonel personel filtreli ($pf). */
    private static function indirimSql(string $pf): string
    {
        return '(COALESCE((SELECT SUM(COALESCE(indirim_tutari,0)) FROM adisyon_hizmetler WHERE adisyon_id=adisyonlar.id'.$pf.'),0)'
            .'+COALESCE((SELECT SUM(COALESCE(indirim_tutari,0)) FROM adisyon_urunler WHERE adisyon_id=adisyonlar.id'.$pf.'),0)'
            .'+COALESCE((SELECT SUM(COALESCE(indirim_tutari,0)) FROM adisyon_paketler WHERE adisyon_id=adisyonlar.id'.$pf.'),0))';
    }

    /** ODENEN (per adisyonlar.id) — kalem tahsilat tablolari, opsiyonel $pf. */
    public static function odenenSql(string $pf = ''): string
    {
        return '(COALESCE((SELECT SUM(t.tutar) FROM tahsilat_hizmetler t WHERE t.adisyon_hizmet_id IN (SELECT id FROM adisyon_hizmetler WHERE adisyon_id=adisyonlar.id'.$pf.')),0)'
            .'+COALESCE((SELECT SUM(t.tutar) FROM tahsilat_urunler t WHERE t.adisyon_urun_id IN (SELECT id FROM adisyon_urunler WHERE adisyon_id=adisyonlar.id'.$pf.')),0)'
            .'+COALESCE((SELECT SUM(t.tutar) FROM tahsilat_paketler t WHERE t.adisyon_paket_id IN (SELECT id FROM adisyon_paketler WHERE adisyon_id=adisyonlar.id'.$pf.')),0))';
    }

    public function liste(int $salonId, array $o): array
    {
        $tarih1 = $o['tarih1'] ?? null;
        $tarih2 = $o['tarih2'] ?? null;
        $adisyonturu = (int) ($o['adisyonturu'] ?? 0);   // 0 tumu / 1 hizmet / 2 paket / 3 urun
        $musteriId = $o['musteri_id'] ?? null;
        $acikKapali = $o['acik_kapali'];                 // '1' acik / '0' kapali / null-"-1" hepsi
        $acikKapali = ($acikKapali === null || $acikKapali === '') ? -1 : (int) $acikKapali;
        $musteriMi = ! empty($o['musteriMi']);
        $appBundle = $o['appBundle'] ?? null;
        $fatura = isset($o['fatura']) && $o['fatura'] !== '' && $o['fatura'] !== null ? (string) $o['fatura'] : null; // '1' faturali / '0' faturasiz / null
        $page = max(1, (int) ($o['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($o['per_page'] ?? 20)));

        // Kapsam kisiti (controller): tum_satis_gor yoksa KENDI personel id'si.
        // Varsa UI'dan gelen personel_id yerine gecer (kullanici baskasini goremez).
        $pid = $o['kisit_personel_id'] ?? ($o['personel_id'] ?: null);
        $pid = $pid ? (int) $pid : null;

        // Tarih varsayilani: musteri karti -> tum gecmis, aksi halde icinde bulunulan ay.
        if (! $tarih1 || ! $tarih2) {
            if ($musteriId) {
                $tarih1 = '1970-01-01';
                $tarih2 = date('Y-m-t');
            } else {
                $tarih1 = date('Y-m-01');
                $tarih2 = date('Y-m-t');
            }
        }

        // Salon kapsami: musteri paneli (white-label) -> bundle'a ait TUM subeler.
        $salonIdler = $musteriMi && $appBundle
            ? Salonlar::where('app_bundle', $appBundle)->pluck('id')->map(fn ($v) => (int) $v)->all()
            : [$salonId];
        if (empty($salonIdler)) {
            $salonIdler = [$salonId];
        }

        // faturasiz_gizle ayari (salon seviyesinde) -> yalnizca fatura kesilmis.
        $faturasizGizle = (bool) Salonlar::where('id', $salonId)->value('faturasiz_gizle');
        // STUDYO: fiyat 0 tutulur -> acik/kapali KALAN ile belirlenemez, 'odendi' esas.
        $studyoModu = Salonlar::studyoModu($salonId);

        $pf = $pid ? ' AND personel_id = ?' : '';

        // ───────── Taban sorgu (eager load + filtreler) ─────────
        $base = Adisyonlar::query()
            ->with([
                'musteri:id,name',
                'salon:id,salon_adi',
                'hizmetler' => function ($q) use ($pid) {
                    $q->with(['hizmet:id,hizmet_adi', 'personel', 'tahsilatlar']);
                    if ($pid) {
                        $q->where('personel_id', $pid);
                    }
                },
                'urunler' => function ($q) use ($pid) {
                    $q->with(['urun:id,urun_adi', 'personel', 'tahsilatlar']);
                    if ($pid) {
                        $q->where('personel_id', $pid);
                    }
                },
                'paketler' => function ($q) use ($pid) {
                    $q->with(['paket:id,paket_adi', 'personel', 'tahsilatlar']);
                    if ($pid) {
                        $q->where('personel_id', $pid);
                    }
                },
            ])
            ->whereIn('salon_id', $salonIdler)
            ->whereBetween('adisyonlar.tarih', [$tarih1, $tarih2])
            ->when($musteriId, fn ($q) => $q->where('adisyonlar.user_id', $musteriId))
            ->when($pid, fn ($q) => $q->where(function ($q2) use ($pid) {
                $q2->whereHas('hizmetler', fn ($s) => $s->where('personel_id', $pid))
                    ->orWhereHas('urunler', fn ($s) => $s->where('personel_id', $pid))
                    ->orWhereHas('paketler', fn ($s) => $s->where('personel_id', $pid));
            }))
            // Hizmet sekmesi: PAKET (randevu_id NULL & seans_sayisi>0) haric hizmet.
            ->when($adisyonturu === 1, fn ($q) => $q->whereHas('hizmetler', fn ($s) => $s->where(function ($w) {
                $w->whereNotNull('randevu_id')->orWhereNull('seans_sayisi')->orWhere('seans_sayisi', '<=', 0);
            })))
            ->when($adisyonturu === 3, fn ($q) => $q->whereHas('urunler'))
            // Paket sekmesi: gercek paket VEYA seansli hizmet.
            ->when($adisyonturu === 2, fn ($q) => $q->where(function ($q2) {
                $q2->whereHas('paketler')
                    ->orWhereHas('hizmetler', fn ($s) => $s->whereNull('randevu_id')->where('seans_sayisi', '>', 0));
            }))
            // Fatura filtresi: acik secim varsa onu uygula; yoksa salon faturasiz_gizle ayari.
            ->when($fatura === '1' || $fatura === '0', fn ($q) => $q->where('fatura_kesildi', (int) $fatura))
            ->when($fatura === null && $faturasizGizle, fn ($q) => $q->where('fatura_kesildi', 1))
            // Tablo araması: satırda GÖRÜNEN içerik — adisyon no, müşteri adı,
            // hizmet/ürün/paket adları ve kalemi yapan personel adı.
            ->when(ListeIstegi::like($o['ara'] ?? null), function ($q, $like) use ($o) {
                $q->where(function ($w) use ($like, $o) {
                    $ara = trim((string) $o['ara']);
                    if (ctype_digit($ara)) {
                        $w->orWhere('adisyonlar.id', (int) $ara);
                    }
                    $w->orWhereHas('musteri', fn ($m) => $m->where('name', 'like', $like))
                        ->orWhereHas('hizmetler.hizmet', fn ($k) => $k->where('hizmet_adi', 'like', $like))
                        ->orWhereHas('urunler.urun', fn ($k) => $k->where('urun_adi', 'like', $like))
                        ->orWhereHas('paketler.paket', fn ($k) => $k->where('paket_adi', 'like', $like))
                        ->orWhereHas('hizmetler.personel', fn ($k) => $k->where('personel_adi', 'like', $like))
                        ->orWhereHas('urunler.personel', fn ($k) => $k->where('personel_adi', 'like', $like))
                        ->orWhereHas('paketler.personel', fn ($k) => $k->where('personel_adi', 'like', $like));
                });
            });

        // Sıralama (beyaz liste controller'da; $yon yalnız asc|desc). Her kolon satırda
        // gösterilen değerle AYNI ifadeyle sıralanır (personel filtresi $pf dahil).
        $yon = ($o['yon'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $pb = fn (int $adet) => $pid ? array_fill(0, $adet, $pid) : [];
        $bakiyeSql = '('.self::netSql($pf).' - '.self::odenenSql($pf).')';
        switch ($o['sirala'] ?? 'tarih') {
            case 'musteri':
                $base->orderBy(User::select('name')->whereColumn('users.id', 'adisyonlar.user_id')->limit(1), $yon);
                break;
            case 'durum': // artan: açık önce
                $studyoModu
                    ? $base->orderByRaw('COALESCE(adisyonlar.odendi,0) '.$yon)
                    : $base->orderByRaw('(CASE WHEN '.$bakiyeSql.' > 0 THEN 0 ELSE 1 END) '.$yon, $pb(6));
                break;
            case 'yaklasan': // satırdaki "Yaklaşan Ödeme" = müşterinin en yakın planlanan alacağı
                $base->orderByRaw('(SELECT MIN(al.planlanan_odeme_tarihi) FROM alacaklar al WHERE al.user_id = adisyonlar.user_id) '.$yon);
                break;
            case 'indirim':
                $base->orderByRaw(self::indirimSql($pf).' '.$yon, $pb(3));
                break;
            case 'toplam':
                $base->orderByRaw(self::netSql($pf).' '.$yon, $pb(3));
                break;
            case 'odenen':
                $base->orderByRaw(self::odenenSql($pf).' '.$yon, $pb(3));
                break;
            case 'kalan':
                $base->orderByRaw($bakiyeSql.' '.$yon, $pb(6));
                break;
            case 'odeme_tarihi':
                $base->orderBy('adisyonlar.odendi_tarihi', $yon);
                break;
            default:
                $base->orderBy('adisyonlar.tarih', $yon);
        }
        $base->orderBy('adisyonlar.id', $yon);

        // ───────── Acik/Kapali sayim (acik_kapali filtresi UYGULANMADAN) ─────────
        if ($studyoModu) {
            $sayac = (clone $base)
                ->toBase()
                ->selectRaw('SUM(CASE WHEN COALESCE(adisyonlar.odendi,0) <> 1 THEN 1 ELSE 0 END) acik, SUM(CASE WHEN COALESCE(adisyonlar.odendi,0) = 1 THEN 1 ELSE 0 END) kapali')
                ->reorder()
                ->first();
        } else {
            $bakiye = self::netSql($pf).' - '.self::odenenSql($pf);
            $primBind = $pid ? array_fill(0, 6, $pid) : [];
            $sayac = DB::query()
                ->fromSub(
                    (clone $base)->toBase()->reorder()->selectRaw('('.$bakiye.') as bakiye', $primBind),
                    's'
                )
                ->selectRaw('SUM(CASE WHEN s.bakiye > 0 THEN 1 ELSE 0 END) acik, SUM(CASE WHEN s.bakiye <= 0 THEN 1 ELSE 0 END) kapali')
                ->first();
        }
        $acikSayisi = (int) ($sayac->acik ?? 0);
        $kapaliSayisi = (int) ($sayac->kapali ?? 0);

        // ───────── Sayfa sorgusu (acik_kapali filtresi SQL ile) ─────────
        if ($acikKapali !== -1) {
            if ($studyoModu) {
                $base->whereRaw('COALESCE(adisyonlar.odendi,0) '.($acikKapali === 1 ? '<> 1' : '= 1'));
            } else {
                $op = $acikKapali === 1 ? '>' : '<=';
                $base->whereRaw(self::netSql($pf).' '.$op.' '.self::odenenSql($pf), $pid ? array_fill(0, 6, $pid) : []);
            }
        }

        $toplamSayisi = $acikKapali === 1 ? $acikSayisi
            : ($acikKapali === 0 ? $kapaliSayisi : $acikSayisi + $kapaliSayisi);

        // ───────── Filtre GENELİ para toplamı (sadece sayfa değil → üst özet kartları) ─────────
        $paraBind = $pid ? array_fill(0, 6, $pid) : [];
        $para = (clone $base)->toBase()->reorder()
            ->selectRaw('COALESCE(SUM('.self::netSql($pf).'),0) as toplam, COALESCE(SUM('.self::odenenSql($pf).'),0) as odenen', $paraBind)
            ->first();
        $toplamTl = (float) ($para->toplam ?? 0);
        $odenenTl = (float) ($para->odenen ?? 0);
        $kalanTl = $toplamTl - $odenenTl;

        $sayfa = $base->forPage($page, $perPage)->get();

        // ───────── Ek veriler (son tahsilat tarihi + planlanan alacak) ─────────
        $adisyonIdler = $sayfa->pluck('id')->all();
        $sonTahsilat = ! empty($adisyonIdler)
            ? DB::table('tahsilatlar')->whereIn('adisyon_id', $adisyonIdler)
                ->select('adisyon_id', DB::raw('MAX(odeme_tarihi) as son'))
                ->groupBy('adisyon_id')->pluck('son', 'adisyon_id')
            : collect();
        $userIdler = $sayfa->pluck('user_id')->unique()->filter()->all();
        $alacaklar = ! empty($userIdler)
            ? Alacaklar::whereIn('user_id', $userIdler)->orderBy('planlanan_odeme_tarihi', 'asc')
                ->get()->groupBy('user_id')->map(fn ($g) => $g->first())
            : collect();

        // Kalem bazli prim haritasi: [personel_id][tur][kalem_id] => yuzde.
        $primHarita = PersonelPrimOrani::harita($salonId);

        // Satir aksiyonlari icin cagiranin (controller) yetki ozeti; verilmezse hic yazma butonu yok.
        $yetkiler = (array) ($o['yetkiler'] ?? []);

        $data = $sayfa->map(function ($a) use ($salonId, $primHarita, $sonTahsilat, $alacaklar, $toplamSayisi, $yetkiler, $studyoModu) {
            $s = AdisyonSatir::hesapla($this->kalemleri($a, $primHarita));

            $alacak = $alacaklar[$a->user_id] ?? null;
            $planlanan = $alacak && $alacak->planlanan_odeme_tarihi
                ? date('d.m.Y', strtotime($alacak->planlanan_odeme_tarihi)) : '';
            $son = $sonTahsilat[$a->id] ?? null;

            // islemler: eski panel ile ayni (Flutter kendi butonunu cizer, bu HTML'i yok sayar).
            if ((float) $s['kalan_tutar_numeric'] == 0) {
                $islemler = '<a style="line-height:5px;padding:5px" title="Ödeme Bilgileri" href="#" name="adisyon_odeme_detaylari" type="button" data-value="'.$a->id.'" class="btn btn-primary"><i class="dw dw-eye"></i></a>';
            } else {
                $islemler = '<a style="line-height:5px;padding:5px" title="Tahsil Et" href="/isletmeyonetim/tahsilat/'.$a->user_id.'/'.$a->id.'?sube='.$salonId.'" type="button" class="btn btn-success"><i class="fa fa-money"></i></a>';
            }
            $islemler .= '&nbsp;<button style="line-height:5px;padding:5px" class="btn btn-danger" href="#" title="Adisyonu Sil" name="adisyon_sil" data-value="'.$a->id.'"><i class="fa fa-times"></i></button>';

            // §data satiri — eski formatAdisyonFast anahtarlariyla BIREBIR (drop-in).
            return [
                'id'                    => $a->id,
                'acilis_tarihi'         => date('d.m.Y', strtotime($a->tarih)),
                'acilis_saati'          => $a->created_at ? date('H:i', strtotime($a->created_at)) : '',
                'son_tahsilat_tarihi'   => $son ? date('d.m.Y', strtotime($son)) : '',
                'musteri'               => $a->musteri->name ?? '-',
                'salon_adi'             => $a->salon->salon_adi ?? '',
                'planlanan_alacak_tarihi' => $planlanan,
                'satis_turu'            => '',
                'satisSayisi'           => $toplamSayisi,
                'icerik'                => $s['icerik'],
                'icerikKisaltilmis'     => $s['icerik_kisaltilmis'],
                'hizmet_veren'          => $s['hizmet_veren'],
                'toplam'                => $s['toplam'],
                'toplam_numeric'        => $s['toplam_numeric'],
                'indirim'               => $s['indirim'],
                'indirim_numeric'       => $s['indirim_numeric'],
                'odenen_numeric'        => $s['odenen_numeric'],
                'kalan_tutar_numeric'   => $s['kalan_tutar_numeric'],
                'odenen'                => $s['odenen'],
                'kalan_tutar'           => $s['kalan_tutar'],
                'hakedisTutar'          => $s['hakedis'],
                'hizmetHakedisTutar'    => $s['hizmet_hakedis'],
                'urunHakedisTutar'      => $s['urun_hakedis'],
                'paketHakedisTutar'     => $s['paket_hakedis'],
                'hizmetToplam'          => $s['hizmet_toplam'],
                'urunToplam'            => $s['urun_toplam'],
                'paketToplam'           => $s['paket_toplam'],
                'islemler'              => $islemler,
                'user_id'               => $a->user_id,
                'durum'                 => $s['durum'],
                'fatura_kesildi'        => (int) ($a->fatura_kesildi ?? 0),
                'odendi'                => (int) ($a->odendi ?? 0),
                'odendi_tarihi'         => $a->odendi_tarihi ? date('d.m.Y H:i', strtotime($a->odendi_tarihi)) : '',
            ] + SatirAksiyon::hesapla(   // + kapali (gosterim durumu) + aksiyonlar (izinli butonlar)
                ['kalan_tutar_numeric' => $s['kalan_tutar_numeric'], 'odendi' => $a->odendi ?? 0],
                $yetkiler,
                $studyoModu
            );
        })->values();

        return [
            'data'          => $data,
            'satisSayisi'   => $toplamSayisi,
            'acik_sayisi'   => $acikSayisi,
            'kapali_sayisi' => $kapaliSayisi,
            // Para toplamlari FILTRE GENELI (tum sayfalar) — ust ozet kartlari.
            'toplam'        => AdisyonSatir::trFormat($toplamTl),
            'toplam_numeric' => round($toplamTl, 2),
            'odenen'        => AdisyonSatir::trFormat($odenenTl),
            'odenen_numeric' => round($odenenTl, 2),
            'kalan'         => AdisyonSatir::trFormat($kalanTl),
            'kalan_numeric' => round($kalanTl, 2),
        ] + ListeIstegi::meta($page, $perPage, $toplamSayisi);
    }

    /** Eager-load edilmis adisyondan AdisyonSatir girdisini (TL kalem dizileri) kurar. */
    private function kalemleri($a, array $primHarita): array
    {
        $hizmetler = [];
        foreach ($a->hizmetler as $h) {
            $seansli = $h->seansliMi();
            $hizmetler[] = [
                'ad'           => $h->hizmet->hizmet_adi ?? '',
                'fiyat'        => $h->fiyat ?? 0,
                'indirim'      => $h->indirim_tutari ?? 0,
                'tahsilat'     => $h->tahsilatlar->sum('tutar'),
                'personel_id'  => $h->personel_id,
                'personel_adi' => $h->personel->personel_adi ?? '',
                'seansli'      => $seansli,
                'seans_sayisi' => (int) ($h->seans_sayisi ?? 0),
                // Seansli hizmet primi PAKET yuzdesinden; aksi halde hizmet orani.
                'prim_yuzde'   => $seansli
                    ? (float) ($h->personel->paket_prim_yuzde ?? 0)
                    : $this->primOrani($h->personel, 'hizmet', $h->hizmet_id, $primHarita),
            ];
        }
        $urunler = [];
        foreach ($a->urunler as $u) {
            $urunler[] = [
                'ad'           => $u->urun->urun_adi ?? '',
                'fiyat'        => $u->fiyat ?? 0,
                'indirim'      => $u->indirim_tutari ?? 0,
                'tahsilat'     => $u->tahsilatlar->sum('tutar'),
                'personel_id'  => $u->personel_id,
                'personel_adi' => $u->personel->personel_adi ?? '',
                'prim_yuzde'   => $this->primOrani($u->personel, 'urun', $u->urun_id, $primHarita),
            ];
        }
        $paketler = [];
        foreach ($a->paketler as $p) {
            $paketler[] = [
                'ad'           => $p->paket->paket_adi ?? '',
                'fiyat'        => $p->fiyat ?? 0,
                'indirim'      => $p->indirim_tutari ?? 0,
                'tahsilat'     => $p->tahsilatlar->sum('tutar'),
                'personel_id'  => $p->personel_id,
                'personel_adi' => $p->personel->personel_adi ?? '',
                'seans_sayisi' => (int) ($p->seans_sayisi ?? 0),
                'prim_yuzde'   => $this->primOrani($p->personel, 'paket', $p->paket_id, $primHarita),
            ];
        }

        return ['hizmetler' => $hizmetler, 'urunler' => $urunler, 'paketler' => $paketler];
    }

    /** Kaleme uygulanacak prim yuzdesi: {tur}_prim_detayli ise kalem orani, degilse genel yuzde. */
    private function primOrani($personel, string $tur, $kalemId, array $primHarita): float
    {
        if (! $personel) {
            return 0.0;
        }
        if (! empty($personel->{$tur.'_prim_detayli'})) {
            return (float) ($primHarita[$personel->id][$tur][$kalemId] ?? 0);
        }

        return (float) ($personel->{$tur.'_prim_yuzde'} ?? 0);
    }
}
