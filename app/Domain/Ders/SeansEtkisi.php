<?php

namespace App\Domain\Ders;

use App\Domain\Seans\SeansDurumu;

/**
 * Katilimci durumu degisince paket/seans hakkina ne olacagi — SAF kural (eski panel,
 * eski mobil ve danisan uclarina dagilmis if zincirinin tek kaynagi).
 *
 *   geldi            → seans duser (APS.geldi=1)
 *   telafi           → seans duser (APS.geldi=2, hak saklanir)
 *   gelmedi + studyo → seans duser (APS.geldi=0; stüdyoda no-show da hak yakar)
 *   gelmedi (normal) → iade
 *   rezerve          → iade
 *
 * Donus: yazilacak APS.geldi degeri; null = dusum yok, varsa iade.
 *
 * Bilincli fark: eski danisan ucu stüdyoda gelmedi'yi APS.geldi=1 yaziyordu; Seans
 * Takibi'nde "kullanildi" gorunuyordu. Tum yollar artik ayni kurali kullanir.
 */
final class SeansEtkisi
{
    public static function geldiDegeri(KatilimciDurumu $durum, bool $studyo): ?int
    {
        return match ($durum) {
            KatilimciDurumu::GELDI => SeansDurumu::GELDI->geldi(),
            KatilimciDurumu::TELAFI => SeansDurumu::TELAFI->geldi(),
            KatilimciDurumu::GELMEDI => $studyo ? SeansDurumu::GELMEDI->geldi() : null,
            default => null,
        };
    }
}
