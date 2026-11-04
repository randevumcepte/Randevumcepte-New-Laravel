<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * user_id / randevu_id ile çözülememiş log satırlarının müşteri adını telefondan bulur — eski
 * waLoglariIsimDoldur. Yalnız verilen sayfanın numaraları için, indeks dostu whereIn; users
 * büyük olduğundan expression-join / GROUP BY yapılmaz (o sorgu zaman aşımına uğruyordu).
 */
class AliciAdCozucu
{
    private const HANE = 10;

    /** @param Collection<int,object> $satirlar telefon + musteri_adi alanlı (yerinde doldurulur) */
    public function doldur(Collection $satirlar): void
    {
        $eksik = $satirlar->filter(fn ($s) => empty($s->musteri_adi) && strlen(self::son10($s->telefon)) === self::HANE);
        if ($eksik->isEmpty()) {
            return;
        }

        $varyantlar = $eksik->map(fn ($s) => self::son10($s->telefon))->unique()
            ->flatMap(fn ($t) => [$t, '0'.$t, '90'.$t, '+90'.$t, '0090'.$t])->values()->all();
        $adlar = [];
        foreach (DB::table('users')->whereIn('cep_telefon', $varyantlar)->where('name', '<>', '')->get(['cep_telefon', 'name']) as $u) {
            $adlar[self::son10($u->cep_telefon)] ??= $u->name;
        }
        foreach ($eksik as $s) {
            $s->musteri_adi = $adlar[self::son10($s->telefon)] ?? null;
        }
    }

    private static function son10(?string $telefon): string
    {
        return substr((string) preg_replace('/\D/', '', (string) $telefon), -self::HANE);
    }
}
