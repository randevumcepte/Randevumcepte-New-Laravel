<?php

namespace App\Services\BildirimKuyrugu;

use App\Models\Randevular;
use RuntimeException;

/**
 * Kuyruk satirini turunun isleyicisine verir. Haritada olmayan turde hata metni eskiyle aynidir: eski kod once
 * randevuyu arardi, bu yuzden randevusuz bilinmeyen tur 'Randevu bulunamadi: ' ile duser.
 */
class BildirimYonlendirici
{
    public function yonlendir(object $kayit): void
    {
        $payload = $kayit->payload ? (json_decode($kayit->payload, true) ?: []) : [];
        $sinif = ((array) config('bildirim_kuyrugu.harita'))[$kayit->tur] ?? null;

        if ($sinif === null) {
            if (! Randevular::find($kayit->randevu_id)) {
                throw new RuntimeException('Randevu bulunamadi: '.$kayit->randevu_id);
            }
            throw new RuntimeException('Bilinmeyen tur: '.$kayit->tur);
        }

        $isleyici = app($sinif);
        if (! $isleyici instanceof BildirimIsleyici) {
            throw new RuntimeException('Gecersiz isleyici: '.$sinif);
        }
        $isleyici->isle($kayit, (array) $payload);
    }
}
