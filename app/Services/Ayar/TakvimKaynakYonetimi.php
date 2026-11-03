<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\KaynakTuru;

/**
 * Takvim kaynagi (cihaz / oda) yonetim sozlesmesi — KaynakTabanController her iki turu bu
 * arayuz uzerinden yonetir; tur'e ozel kayit alanlari uygulamalarda.
 */
interface TakvimKaynakYonetimi
{
    public function tur(): KaynakTuru;

    /** @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $o */
    public function listele(int $salonId, array $o, bool $tumu = false): array;

    public function detay(int $salonId, int $id): array;

    public function formVerisi(int $salonId): array;

    public function kaydet(int $salonId, int $yapanId, ?int $id, array $g): array;
}
