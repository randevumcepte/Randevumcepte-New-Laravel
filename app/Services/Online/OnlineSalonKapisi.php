<?php

namespace App\Services\Online;

use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Musteri tarafi uclarinin ortak salon cozumu + online randevu kapisi — TEK KAYNAK.
 * Eski sistemde musteri_online_randevu_aktif yalniz butonlari gizliyordu; kayit ucu
 * denetlemiyordu. Burada saat listesi ve kayit ayni kapidan gecer.
 */
class OnlineSalonKapisi
{
    private const PLATFORM_ADI = 'Randevumcepte';

    public function salon(int $salonId): object
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first();
        if (! $salon) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        return $salon;
    }

    /** İsteğin geldiği alan adının salonu (eski salonDetay: salonlar.domain, "www." yok sayılır). */
    public function domaindenSalonId(string $host): ?int
    {
        $host = preg_replace('/^www\./i', '', mb_strtolower(trim($host)));
        if ($host === '') {
            return null;
        }
        $id = DB::table('salonlar')->whereIn('domain', [$host, 'www.'.$host])->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function randevuyaAcikSalon(int $salonId): object
    {
        $salon = $this->salon($salonId);
        if (! $this->randevuyaAcikMi($salon)) {
            throw new GecersizIslemException('Bu işletme şu an online randevu almıyor.');
        }

        return $salon;
    }

    public function randevuyaAcikMi(object $salon): bool
    {
        return (int) ($salon->musteri_online_randevu_aktif ?? 0) === 1 && $this->lisansAktif($salon);
    }

    public function lisansAktif(object $salon): bool
    {
        return empty($salon->uyelik_bitis_tarihi) || strtotime((string) $salon->uyelik_bitis_tarihi) >= strtotime('today');
    }

    /** Web: istegin host'u. Flutter: paket adi. Bir bundle birden cok subeye baglanabilir. */
    public function domaindenSalon(string $domain): object
    {
        $host = preg_replace('/^www\./i', '', mb_strtolower(trim($domain)));
        $salon = DB::table('salonlar')->whereIn('domain', [$host, 'www.'.$host])->first();
        if (! $salon) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        return $salon;
    }

    /** Mesajlarda gorunen marka adi: marka basligi, yoksa ilk sube, yoksa platform adi. */
    public function markaAdi(?string $bundle): string
    {
        if (! $bundle) {
            return self::PLATFORM_ADI;
        }
        $baslik = Schema::hasTable('app_bundle_ayarlari') ? DB::table('app_bundle_ayarlari')->where('app_bundle', $bundle)->value('bundle_baslik') : null;

        return $baslik ?: (DB::table('salonlar')->where('app_bundle', $bundle)->orderBy('id')->value('salon_adi') ?: self::PLATFORM_ADI);
    }

    /** @return int[] */
    public function bundleSalonlari(string $bundle): array
    {
        return DB::table('salonlar')->where('app_bundle', $bundle)->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
    }
}
