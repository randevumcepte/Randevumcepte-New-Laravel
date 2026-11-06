<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/** ListeIstegi sozlesmesini SQL sorgusuna uygular: arama, beyaz listeli siralama, sayfalama. */
final class SorguSayfasi
{
    /**
     * @param  array{page:int,per_page:int,ara?:?string,sirala?:?string,yon:string}  $liste  ListeIstegi::dizi()
     * @param  list<string>  $aramaKolonlari  bos: arama cagirana ait
     * @param  array<string,string>|callable(Builder):void  $sirala  beyaz liste (alan => kolon; ilki varsayilan) ya da ozel siralama
     * @param  callable(object):array  $satir
     * @return array{data:array,current_page:int,last_page:int,per_page:int,total:int}
     */
    public static function uret(Builder $q, array $liste, array $aramaKolonlari, array|callable $sirala, callable $satir, string $idKolonu = 'id'): array
    {
        $like = $aramaKolonlari ? ListeIstegi::like($liste['ara'] ?? null) : null;
        if ($like !== null) {
            $q->where(function ($w) use ($aramaKolonlari, $like) {
                foreach ($aramaKolonlari as $kolon) {
                    $w->orWhere($kolon, 'like', $like);
                }
            });
        }

        $toplam = (clone $q)->count();

        if (is_array($sirala)) {
            $q->orderBy($sirala[$liste['sirala'] ?? ''] ?? reset($sirala), $liste['yon'])->orderByDesc($idKolonu);
        } else {
            $sirala($q);
        }

        $satirlar = $q->forPage($liste['page'], $liste['per_page'])->get()->map(fn ($s) => $satir($s))->values()->all();

        return ['data' => $satirlar] + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }
}
