<?php

namespace App\Services\Sms\Bakiye;

/**
 * Bir SMS saglayicisinin kalan bakiyesini (adet) okuyan strateji.
 * Yeni saglayici = yeni sinif + SmsBakiyeServisi::saglayici() eslemesi (cagiranlar degismez).
 */
interface SmsBakiyeSaglayici
{
    /** @param object $salon salonlar satiri (saglayici kimlik alanlari). Okunamazsa null. */
    public function bakiye(object $salon): ?int;
}
