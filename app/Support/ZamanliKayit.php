<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** created_at + updated_at damgali tablo eklemesi (paylasilan semada Eloquent modeli olmayan tablolar icin). */
final class ZamanliKayit
{
    public static function ekle(string $tablo, array $satir): int
    {
        $simdi = now();

        return (int) DB::table($tablo)->insertGetId($satir + ['created_at' => $simdi, 'updated_at' => $simdi]);
    }

    /** @param list<array<string,mixed>> $satirlar */
    public static function topluEkle(string $tablo, array $satirlar): void
    {
        $simdi = now();
        DB::table($tablo)->insert(array_map(fn (array $s) => $s + ['created_at' => $simdi, 'updated_at' => $simdi], $satirlar));
    }
}
