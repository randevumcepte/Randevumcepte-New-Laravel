<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * takvim_sirasi kolonu olan kaynaklarin (personel, cihaz, oda) sira islemleri — TEK KAYNAK.
 *
 * Eski panel bu mantigi uc kez kopyalamisti (personel/cihaz/oda): index tabanli komsu
 * degisimi + olasi UNIQUE kisit icin negatif gecici degerle 3 adimli swap. Burada ayrica
 * once 0..n-1 yeniden numaralandirilir (eski cihaz/oda listesindeki "self-heal"); boylece
 * tekrar eden / NULL siralar yuzunden "degismiyor / atliyor" hatasi olusmaz.
 */
final class TakvimSirasi
{
    /**
     * Kosula uyan kayitlari (takvim_sirasi, id) sirasiyla 0..n-1 numaralar.
     *
     * @param  array<string,mixed>  $kosul  where esitlikleri (orn. ['salon_id'=>5,'aktifmi'=>1])
     * @return list<int> sirali id listesi
     */
    public static function numarala(string $tablo, array $kosul): array
    {
        $satirlar = DB::table($tablo)->where($kosul)->orderBy('takvim_sirasi')->orderBy('id')->get(['id', 'takvim_sirasi']);
        foreach ($satirlar as $i => $s) {
            if ($s->takvim_sirasi === null || (int) $s->takvim_sirasi !== $i) {
                DB::table($tablo)->where('id', $s->id)->update(['takvim_sirasi' => $i]);
            }
        }

        return $satirlar->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * $id'yi bir yukari (-1) / asagi (+1) tasir.
     *
     * @return 'ok'|'notfound'|'sinir'
     */
    public static function kaydir(string $tablo, array $kosul, int $id, int $delta): string
    {
        if ($delta !== 1 && $delta !== -1) {
            throw new \InvalidArgumentException('delta 1 veya -1 olmalı');
        }

        return DB::transaction(function () use ($tablo, $kosul, $id, $delta) {
            $idler = self::numarala($tablo, $kosul);
            $i = array_search($id, $idler, true);
            if ($i === false) {
                return 'notfound';
            }
            $j = $i + $delta;
            if (! isset($idler[$j])) {
                return 'sinir';
            }
            // Numaralandirma sonrasi sira = index. 3 adim: a → gecici, b → a'nin yeri, a → b'nin yeri.
            DB::table($tablo)->where('id', $idler[$i])->update(['takvim_sirasi' => -1 * abs($idler[$i])]);
            DB::table($tablo)->where('id', $idler[$j])->update(['takvim_sirasi' => $i]);
            DB::table($tablo)->where('id', $idler[$i])->update(['takvim_sirasi' => $j]);

            return 'ok';
        });
    }

    /** Yeni kayit icin siradaki deger (kosuldaki en buyuk + 1; bos ise 0). */
    public static function sonraki(string $tablo, array $kosul): int
    {
        $max = DB::table($tablo)->where($kosul)->max('takvim_sirasi');

        return $max === null ? 0 : (int) $max + 1;
    }
}
