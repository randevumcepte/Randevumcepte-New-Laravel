<?php

namespace App\Services\Bildirim;

use Illuminate\Database\Query\Builder;

/**
 * Bir kişinin bildirim kutusu: hangi satırlar ona ait ve ona nasıl sunulur. Okuma/yazma servisleri
 * yalnız bu sözleşmeyi bilir; yeni alıcı türü servis değişmeden eklenir.
 */
interface BildirimKutusu
{
    /** Sorguyu bu kutunun satırlarıyla sınırlar (başka kişinin bildirimi hiçbir uçtan görünmez/değişmez). */
    public function kapsamla(Builder $q): void;

    public function sun(object $satir, ?string $arsivUzanti, ?int $randevuMusterisi): array;

    /** Toplu işlemlerin aktivite logu salonu (bilinmiyorsa log yazılmaz). */
    public function salonId(): ?int;

    /** İşlemi yapan yetkili (müşteride null). */
    public function yetkiliId(): ?int;
}
