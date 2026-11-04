<?php

namespace App\Services\Hesabim\Hizmet;

use App\Domain\Hesabim\HizmetKarti;

/**
 * "Aldığım Hizmetler" listesine kart katan kaynak. Yeni hizmet = yeni sınıf +
 * HesabimHizmetleri kurucusuna ekleme; mevcut kaynaklar değişmez.
 */
interface HizmetKaynagi
{
    /**
     * @param  object  $salon  salonlar satırı
     * @return list<HizmetKarti>
     */
    public function kartlar(object $salon): array;
}
