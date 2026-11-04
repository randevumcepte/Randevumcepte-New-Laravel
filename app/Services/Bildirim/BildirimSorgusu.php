<?php

namespace App\Services\Bildirim;

use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bildirim OKUMA — eski bildirimgetir, bildirimgetirmusteri, bildirimkontrolet, /bildirim/liste ve
 * /bildirim/okunmamis-sayi uçlarının TEK karşılığı. Satır biçimi kutunun (BildirimKutusu::sun).
 */
class BildirimSorgusu
{
    /**
     * @param  array{page:int, per_page:int, ara:?string}  $liste
     * @return array{data:list<array>, sayac:array{okunmamis:int,toplam:int}, current_page:int, last_page:int, per_page:int, total:int}
     */
    public function listele(BildirimKutusu $kutu, array $liste, bool $yalnizOkunmamis): array
    {
        $q = $this->kutuSorgusu($kutu);
        if ($yalnizOkunmamis) {
            $q->where('okundu', 0);
        }
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn (Builder $w) => $w->where('aciklama', 'like', $like)->orWhere('baslik', 'like', $like));
        }
        $toplam = (clone $q)->count();
        $satirlar = $q->orderByDesc('id')->forPage($liste['page'], $liste['per_page'])->get();

        return [
            'data'  => $this->sunulmus($kutu, $satirlar),
            'sayac' => $this->sayac($kutu),
        ] + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    /** @return array{okunmamis:int, toplam:int} Sayaçlar liste limitinden bağımsız. */
    public function sayac(BildirimKutusu $kutu): array
    {
        $s = $this->kutuSorgusu($kutu)->selectRaw('COUNT(*) as toplam, COALESCE(SUM(CASE WHEN okundu = 0 THEN 1 ELSE 0 END), 0) as okunmamis')->first();

        return ['okunmamis' => (int) $s->okunmamis, 'toplam' => (int) $s->toplam];
    }

    /** Kutu kapsamındaki bildirimler için taban sorgu (yazma servisi de kullanır). */
    public function kutuSorgusu(BildirimKutusu $kutu): Builder
    {
        $q = DB::table('bildirimler');
        $kutu->kapsamla($q);

        return $q;
    }

    /** Arşiv ve randevu bilgisi sayfa başına topluca okunur (eski eager load her satırda 5 ilişki çekiyordu). */
    private function sunulmus(BildirimKutusu $kutu, Collection $satirlar): array
    {
        $idler = fn (string $kolon) => $satirlar->map(fn ($r) => $r->{$kolon} ?? null)->filter()->unique()->values();
        $arsivIdleri = $idler('arsiv_id');
        $randevuIdleri = $idler('randevu_id');
        $uzantilar = $arsivIdleri->isEmpty() ? collect() : DB::table('arsiv')->whereIn('id', $arsivIdleri)->pluck('uzanti', 'id');
        $musteriler = $randevuIdleri->isEmpty() ? collect() : DB::table('randevular')->whereIn('id', $randevuIdleri)->pluck('user_id', 'id');

        return $satirlar->map(fn ($r) => $kutu->sun(
            $r,
            $uzantilar[$r->arsiv_id ?? 0] ?? null,
            isset($musteriler[$r->randevu_id ?? 0]) ? (int) $musteriler[$r->randevu_id] : null,
        ))->values()->all();
    }
}
