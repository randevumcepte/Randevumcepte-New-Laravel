<?php

namespace App\Services\Cark;

use App\Domain\Cark\DilimTipi;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Çevirme GEÇMİŞİ (carkifelek_cevirme_loglari) + Kazananlar sekmesinin özet kartları — salt okuma.
 * Eski web carkKazananlar'ın "Son Çevirme Logları" (500 sınırlı, sayfasız) yerine sayfalı
 * ListeIstegi listesi. Dönem bazlı pano özeti ayrı: Services/Pazarlama/CarkOzetServisi.
 */
class CarkGecmisServisi
{
    private const LOG = 'carkifelek_cevirme_loglari';

    public const SIRALAMA = ['tarih' => 'l.created_at', 'dilim' => 'l.dilim_ismi'];

    /** @return array{toplam_cevirme:int, bugun_cevirme:int, kullanilmamis:int, kullanilmis:int} */
    public function ozet(int $salonId): array
    {
        $bugun = now()->toDateString();
        $c = DB::table(self::LOG)->where('salon_id', $salonId)
            ->selectRaw('COUNT(*) as toplam, SUM(created_at >= ?) as bugun', [$bugun.' 00:00:00'])->first();
        $k = DB::table('carkifelek_odulleri')->where('salon_id', $salonId)
            ->selectRaw('SUM(kullanildi = 0) as bekleyen, SUM(kullanildi = 1) as kullanilan')->first();

        return [
            'toplam_cevirme' => (int) $c->toplam,
            'bugun_cevirme'  => (int) $c->bugun,
            'kullanilmamis'  => (int) $k->bekleyen,
            'kullanilmis'    => (int) $k->kullanilan,
        ];
    }

    /** @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $l */
    public function cevirmeler(int $salonId, array $l): array
    {
        $q = DB::table(self::LOG.' as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->where('l.salon_id', $salonId);
        if ($desen = ListeIstegi::like($l['ara'])) {
            $q->where(fn (Builder $w) => $w->where('u.name', 'like', $desen)->orWhere('l.dilim_ismi', 'like', $desen));
        }
        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy(self::SIRALAMA[$l['sirala']] ?? self::SIRALAMA['tarih'], $l['yon'] === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('l.id')->forPage($l['page'], $l['per_page'])
            ->get(['l.id', 'l.created_at', 'l.user_id', 'l.dilim_ismi', 'l.tip', 'l.deger', 'u.name']);

        return ['data' => $satirlar->map(fn ($r) => [
            'id'         => (int) $r->id,
            'tarih'      => $r->created_at,
            // Misafir çevirmesinde user_id boş olabilir.
            'musteri'    => $r->user_id ? (trim((string) $r->name) ?: 'Müşteri #'.$r->user_id) : 'Misafir',
            'dilim_ismi' => $r->dilim_ismi,
            'tip'        => DilimTipi::gecerli($r->tip),
            'odul'       => DilimTipi::odulMetni((string) $r->tip, $r->deger !== null ? (float) $r->deger : null, null),
        ])->all()] + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }
}
