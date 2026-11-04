<?php

namespace App\Services\MusteriHesap;

use Illuminate\Support\Facades\DB;

/**
 * Musterinin kendi akisinda (kayit, giris, online randevu) salon portfoyune girmesi.
 * Eski App\MusteriPortfoy'un olusturma kancalariyla ayni alanlar: yeni satir KVKK onaysiz baslar.
 */
class PortfoyKaydi
{
    public function garantile(int $salonId, int $userId): void
    {
        $satir = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->orderByDesc('aktif')->first(['id']);
        if ($satir) {
            return;
        }
        DB::table('musteri_portfoy')->insert([
            'salon_id' => $salonId, 'user_id' => $userId, 'tur' => 1, 'aktif' => 1, 'kara_liste' => 0,
            'kvkk_onay_alindi' => 0, 'onay_kodu' => (string) random_int(1000, 9999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
