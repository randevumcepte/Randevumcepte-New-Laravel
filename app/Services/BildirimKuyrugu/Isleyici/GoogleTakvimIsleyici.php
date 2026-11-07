<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\GoogleTakvimServisi;
use Illuminate\Support\Facades\DB;

/** Google Takvim senkronu; eski RandevuHizmetler model event'inin karsiligi. Randevu silinmis olabilir, randevu sartsiz. Once silme, sonra yazma. */
class GoogleTakvimIsleyici implements BildirimIsleyici
{
    public function __construct(private GoogleTakvimServisi $takvim) {}

    public function isle(object $kayit, array $payload): void
    {
        foreach ((array) ($payload['sil'] ?? []) as $rhId) {
            $this->takvim->randevuHizmetiSil((int) $rhId);
        }
        foreach ((array) ($payload['sync'] ?? []) as $rhId) {
            $rh = DB::table('randevu_hizmetler')->where('id', (int) $rhId)->first();
            if ($rh && $rh->personel_id && $rh->randevu_id) {
                $this->takvim->randevuHizmetiYaz($rh);
            }
        }
    }
}
