<?php

namespace App\Services\Satis;

use App\Domain\Satis\AdisyonSatir;
use App\Models\Adisyonlar;
use App\Models\Alacaklar;
use App\Models\PersonelPrimOrani;
use App\Models\Salonlar;
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
    private static function netSql(string $pf): string
    {
        return '(COALESCE((SELECT SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)) FROM adisyon_hizmetler WHERE adisyon_id=adisyonlar.id'.$pf.'),0)'
            .'+COALESCE((SELECT SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)) FROM adisyon_urunler WHERE adisyon_id=adisyonlar.id'.$pf.'),0)'
            .'+COALESCE((SELECT SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)) FROM adisyon_paketler WHERE adisyon_id=adisyonlar.id'.$pf.'),0))';
    }

    /** ODENEN (per adisyonlar.id) — kalem tahsilat tablolari, opsiyonel $pf. */
    private static function odenenSql(string $pf): string
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
        $studyoModu = (int) Salonlar::where('id', $salonId)->value('studyo_modu') === 1;

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
            ->when($faturasizGizle, fn ($q) => $q->where('fatura_kesildi', 1))
            ->orderBy('tarih', 'desc');

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
        $lastPage = (int) max(1, ceil(($toplamSayisi ?: 1) / $perPage));

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
        $primHarita = $this->primHaritasi($salonId);

        $odenenToplam = 0.0;
        $kalanToplam = 0.0;

        $data = $sayfa->map(function ($a) use ($salonId, $primHarita, $sonTahsilat, $alacaklar, $toplamSayisi, &$odenenToplam, &$kalanToplam) {
            $s = AdisyonSatir::hesapla($this->kalemleri($a, $primHarita));
            $odenenToplam += $s['odenen_numeric'];
            $kalanToplam += $s['kalan_tutar_numeric'];

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
            ];
        })->values();

        return [
            'data'          => $data,
            'satisSayisi'   => $toplamSayisi,
            'acik_sayisi'   => $acikSayisi,
            'kapali_sayisi' => $kapaliSayisi,
            'odenen'        => AdisyonSatir::trFormat($odenenToplam),
            'kalan'         => AdisyonSatir::trFormat($kalanToplam),
            'current_page'  => $page,
            'last_page'     => $lastPage,
            'total'         => $toplamSayisi,
        ];
    }

    /** Eager-load edilmis adisyondan AdisyonSatir girdisini (TL kalem dizileri) kurar. */
    private function kalemleri($a, array $primHarita): array
    {
        $hizmetler = [];
        foreach ($a->hizmetler as $h) {
            $seansli = is_null($h->randevu_id) && (int) ($h->seans_sayisi ?? 0) > 0;
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

    /** personel_prim_oranlari -> [personel_id][tur][kalem_id] => yuzde (eski detayliPrimHaritasi). */
    private function primHaritasi(int $salonId): array
    {
        $harita = [];
        foreach (PersonelPrimOrani::where('salon_id', $salonId)->get() as $s) {
            $harita[$s->personel_id][$s->tur][$s->kalem_id] = (float) $s->yuzde;
        }

        return $harita;
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
