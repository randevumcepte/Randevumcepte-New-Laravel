<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\CagriMerkeziEylemi;
use App\Domain\CagriMerkezi\SantralKaydi;
use Illuminate\Support\Facades\DB;

/**
 * Çağrı merkezi PERFORMANS paneli (yönetici) — eski arama_dashboard_verileri +
 * arama_dashboard_personel_detay. Personel = arama_listesi.personel_id; görüşme metrikleri
 * gorusme_notlari'ndan. Sorgular personel başına TEK grup (N+1 yok). Hiçbir şey yazmaz.
 */
class PerformansServisi
{
    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private AramaListesiServisi $listeler,
    ) {}

    public function kartlar(CagriMerkeziErisim $e): array
    {
        $this->erisim->eylemZorunlu($e, CagriMerkeziEylemi::Performans);
        $zamanli = "am.tarih IS NOT NULL AND am.tarih<>'' AND am.saat IS NOT NULL AND am.saat<>''";
        $rows = DB::table('aranacak_musteriler as am')->join('arama_listesi as al', 'al.id', '=', 'am.arama_id')
            ->where('al.salon_id', $e->salonId)->whereNotNull('al.personel_id')
            ->groupBy('al.personel_id')
            ->get([
                'al.personel_id',
                DB::raw('COUNT(*) as atanan'),
                DB::raw('SUM(CASE WHEN am.durum IS NOT NULL THEN 1 ELSE 0 END) as aranan'),
                DB::raw('SUM(CASE WHEN am.durum IN (2,5) THEN 1 ELSE 0 END) as cevapsiz'),
                DB::raw('SUM(CASE WHEN am.durum IN (1,4) THEN 1 ELSE 0 END) as konusulan'),
                DB::raw('SUM(CASE WHEN am.durum=0 THEN 1 ELSE 0 END) as ulasilamadi'),
                DB::raw('SUM(CASE WHEN am.durum=3 THEN 1 ELSE 0 END) as randevu'),
                DB::raw('SUM(CASE WHEN am.durum=6 THEN 1 ELSE 0 END) as ongorusme'),
                DB::raw('SUM(CASE WHEN am.durum=7 THEN 1 ELSE 0 END) as satis'),
                DB::raw("SUM(CASE WHEN am.durum=3 AND $zamanli AND (am.ar_gecikti=1 OR CONCAT(am.tarih,' ',am.saat) < NOW()) THEN 1 ELSE 0 END) as gecikti"),
                DB::raw("SUM(CASE WHEN am.ar_tamamlandi_at IS NOT NULL AND $zamanli AND CONCAT(am.tarih,' ',am.saat) < am.ar_tamamlandi_at THEN 1 ELSE 0 END) as gec_aranan"),
                DB::raw("SUM(CASE WHEN am.ar_tamamlandi_at IS NOT NULL AND $zamanli AND CONCAT(am.tarih,' ',am.saat) >= am.ar_tamamlandi_at THEN 1 ELSE 0 END) as zamaninda_aranan"),
            ]);

        $gn = DB::table('gorusme_notlari')->where('salon_id', $e->salonId)->groupBy('personel_id')
            ->get(['personel_id', DB::raw('SUM(sure_dk) as toplam_dk'), DB::raw('COUNT(*) as gorusme_sayisi'),
                DB::raw('SUM(CASE WHEN sonuc=7 THEN COALESCE(satis_tutari,0) ELSE 0 END) as satis_cirosu')])
            ->keyBy('personel_id');
        $adlar = $this->listeler->personelAdlari($rows->pluck('personel_id')->all());

        $int = fn ($v) => (int) ($v ?? 0);
        $kartlar = $rows->map(function ($r) use ($gn, $adlar, $int) {
            $g = $gn[$r->personel_id] ?? null;

            return [
                'personel_id'      => (int) $r->personel_id,
                'ad'               => $adlar[$r->personel_id] ?? 'Bilinmeyen Personel',
                'atanan'           => $int($r->atanan),
                'aranan'           => $int($r->aranan),
                'kalan'            => $int($r->atanan) - $int($r->aranan),
                'ilerleme'         => $int($r->atanan) > 0 ? (int) round($int($r->aranan) / $int($r->atanan) * 100) : 0,
                'cevapsiz'         => $int($r->cevapsiz),
                'konusulan'        => $int($r->konusulan),
                'ulasilamadi'      => $int($r->ulasilamadi),
                'randevu'          => $int($r->randevu),
                'ongorusme'        => $int($r->ongorusme),
                'satis'            => $int($r->satis),
                'toplam_dk'        => $g ? (int) $g->toplam_dk : 0,
                'gorusme_sayisi'   => $g ? (int) $g->gorusme_sayisi : 0,
                'satis_cirosu'     => $g ? round((float) $g->satis_cirosu, 2) : 0.0,
                'gecikti'          => $int($r->gecikti),
                'gec_aranan'       => $int($r->gec_aranan),
                'zamaninda_aranan' => $int($r->zamaninda_aranan),
            ];
        })->sortByDesc('atanan')->values()->all();

        $topla = fn ($alan) => array_sum(array_column($kartlar, $alan));

        return [
            'kartlar' => $kartlar,
            'ozet'    => ['atanan' => $topla('atanan'), 'konusulan' => $topla('konusulan'), 'cevapsiz' => $topla('cevapsiz'), 'randevu' => $topla('randevu')],
        ];
    }

    /** Bir personelin görüşme dökümü (son 300) + sonuç filtre grupları. */
    public function personelDetay(CagriMerkeziErisim $e, int $personelId): array
    {
        $this->erisim->eylemZorunlu($e, CagriMerkeziEylemi::Performans);
        $this->erisim->personel($personelId, $e);
        $host = (string) config('cagri_merkezi.ses_host');

        $notlar = DB::table('gorusme_notlari as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')
            ->leftJoin('cagri_kategorileri as k', 'k.id', '=', 'g.kategori_id')
            ->leftJoin('cagri_kategorileri as ak', 'ak.id', '=', 'g.alt_kategori_id')
            ->where('g.salon_id', $e->salonId)->where('g.personel_id', $personelId)
            ->orderByDesc('g.id')->limit(300)
            ->get(['g.not', 'g.ses_kaydi', 'g.sonuc', 'g.sure_dk', 'g.satis_tutari', 'g.created_at', 'u.name', 'k.ad as kategori_ad', 'ak.ad as alt_kategori_ad'])
            ->map(fn ($n) => [
                'musteri'      => $n->name ?? '-',
                'not'          => (string) ($n->not ?? ''),
                'sonuc'        => AramaSonucu::metin($n->sonuc),
                'sonuc_kod'    => $n->sonuc !== null ? (int) $n->sonuc : null,
                'renk'         => AramaSonucu::renkKodu($n->sonuc),
                'sure_dk'      => (int) $n->sure_dk,
                'kategori'     => (string) ($n->kategori_ad ?? ''),
                'alt_kategori' => (string) ($n->alt_kategori_ad ?? ''),
                'satis_tutari' => $n->satis_tutari !== null ? (float) $n->satis_tutari : null,
                'ses'          => SantralKaydi::url($n->ses_kaydi, $host),
                'tarih'        => $n->created_at ? date('d.m.Y H:i', strtotime($n->created_at)) : '',
            ])->all();

        return ['notlar' => $notlar, 'gruplar' => AramaSonucu::performansGruplari()];
    }
}
