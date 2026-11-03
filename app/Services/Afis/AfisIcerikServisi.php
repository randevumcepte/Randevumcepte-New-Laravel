<?php

namespace App\Services\Afis;

use App\Domain\Afis\AfisIcerigi;
use App\Domain\Afis\MagazaLinkleri;
use App\Exceptions\BulunamadiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salon kaydından afiş içeriğini + mağaza linklerini okur (SALT OKUMA). Veri kaynağı bilgisi
 * yalnız burada; üretici ve controller DB'yi bilmez.
 */
class AfisIcerikServisi
{
    /** @return array{0: AfisIcerigi, 1: MagazaLinkleri} */
    public function getir(int $salonId): array
    {
        // NOT: salonlar'da 'isletme_adi' kolonu YOK (eski kod Eloquent'te null'a düşüp salon_adi
        // kullanıyordu); seçilirse "Unknown column" → 500. Huawei linki opsiyonel kolon.
        $kolonlar = ['id', 'salon_adi', 'logo', 'android_uygulama', 'ios_uygulama'];
        if (Schema::hasColumn('salonlar', 'huawei_uygulama')) {
            $kolonlar[] = 'huawei_uygulama';
        }
        $salon = DB::table('salonlar')->where('id', $salonId)->first($kolonlar);
        if (! $salon) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        $logo = $this->logoDosyasi($salon->logo);
        $icerik = new AfisIcerigi(
            salonId: (int) $salon->id,
            ad: $salon->salon_adi,
            indirLinki: $this->indirLinki((int) $salon->id),
            logoDosyasi: $logo,
            logoSurumu: $logo !== null ? (int) @filemtime($logo) : 0,
        );

        return [$icerik, new MagazaLinkleri($salon->android_uygulama, $salon->ios_uygulama, $salon->huawei_uygulama ?? null)];
    }

    /** QR hedefi = eski monolitin herkese açık akıllı yönlendirmesi (cihaza göre mağaza). */
    public function indirLinki(int $salonId): string
    {
        return rtrim((string) config('afis.indir_taban'), '/').'/indir/'.$salonId;
    }

    /**
     * DB'deki logo yolu eski monolitin biçiminde ('public/salon_gorselleri/x.png' ya da
     * '/public/…'); monolitin public/ köküne göre çözülür. Dizin dışına çıkış engellenir.
     */
    private function logoDosyasi(?string $yol): ?string
    {
        $goreli = ltrim((string) preg_replace('#^/?public/#', '', trim((string) $yol)), '/');
        if ($goreli === '' || str_contains($goreli, '..')) {
            return null;
        }
        $tam = rtrim((string) config('ayar.eski_public_kok'), '/').'/'.$goreli;

        return is_file($tam) && is_readable($tam) ? $tam : null;
    }
}
