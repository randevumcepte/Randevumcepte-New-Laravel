<?php

namespace App\Services\Afis;

use App\Domain\Afis\AfisIcerigi;
use App\Domain\Afis\MagazaLinkleri;
use App\Exceptions\BulunamadiException;
use Illuminate\Support\Facades\DB;

/**
 * Salon kaydından afiş içeriğini + mağaza linklerini okur (SALT OKUMA). Veri kaynağı bilgisi
 * yalnız burada; üretici ve controller DB'yi bilmez.
 */
class AfisIcerikServisi
{
    /** @return array{0: AfisIcerigi, 1: MagazaLinkleri} */
    public function getir(int $salonId): array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first([
            'id', 'salon_adi', 'isletme_adi', 'logo', 'android_uygulama', 'ios_uygulama', 'huawei_uygulama',
        ]);
        if (! $salon) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        $logo = $this->logoDosyasi($salon->logo);
        $icerik = new AfisIcerigi(
            salonId: (int) $salon->id,
            ad: trim((string) $salon->isletme_adi) !== '' ? $salon->isletme_adi : $salon->salon_adi,
            indirLinki: $this->indirLinki((int) $salon->id),
            logoDosyasi: $logo,
            logoSurumu: $logo !== null ? (int) @filemtime($logo) : 0,
        );

        return [$icerik, new MagazaLinkleri($salon->android_uygulama, $salon->ios_uygulama, $salon->huawei_uygulama)];
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
