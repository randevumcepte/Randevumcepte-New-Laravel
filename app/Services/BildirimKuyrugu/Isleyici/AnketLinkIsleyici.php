<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\AnketLinkGonderici;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Anket linki: kayit ve token v2'de olusur, burada gonderilir. Musteri kaydi yoksa (test gonderimi) yalniz ad ve numara ile. */
class AnketLinkIsleyici implements BildirimIsleyici
{
    public function __construct(private AnketLinkGonderici $gonderici) {}

    public function isle(object $kayit, array $payload): void
    {
        $gonderim = DB::table('anket_gonderimleri')->where('id', (int) ($payload['gonderim_id'] ?? 0))->first();
        $sablon = $gonderim ? DB::table('anket_sablonlari')->where('id', $gonderim->sablon_id)->first() : null;
        if (! $gonderim || ! $sablon) {
            Log::warning('[v2:bildirim-isle] anket_link: gonderim/sablon yok', ['id' => $kayit->id, 'gonderim_id' => $payload['gonderim_id'] ?? null]);

            return;
        }

        $musteri = ($gonderim->user_id ? DB::table('users')->where('id', $gonderim->user_id)->first() : null)
            ?: (object) ['id' => null, 'name' => $gonderim->ad_soyad ?: 'Test'];

        $this->gonderici->gonder($gonderim, $sablon, $musteri);
    }
}
