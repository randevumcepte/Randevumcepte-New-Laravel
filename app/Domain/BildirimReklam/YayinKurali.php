<?php

namespace App\Domain\BildirimReklam;

use App\Support\SalonIdListesi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Reklam yayında mı / müşteriye gösterilir mi — eski BildirimReklamlari::yayindaMi ve
 * BildirimReklamApiController@liste filtresinin TEK kaynağı (saf; DB sayımları dışarıdan verilir).
 */
final class YayinKurali
{
    /**
     * Yayında: durum aktif + yayın penceresi içinde + toplam kupon adedi dolmamış.
     * Kupon kapma bu kurala bakar.
     */
    public static function yayindaMi(object $r, CarbonInterface $simdi): bool
    {
        if (ReklamDurumu::dbden($r->durum ?? null) !== ReklamDurumu::AKTIF) {
            return false;
        }
        if (! empty($r->yayin_baslangic) && CarbonImmutable::parse($r->yayin_baslangic)->greaterThan($simdi)) {
            return false;
        }
        if (! empty($r->yayin_bitis) && CarbonImmutable::parse($r->yayin_bitis)->lessThan($simdi)) {
            return false;
        }

        return ! self::adetDolduMu($r);
    }

    public static function adetDolduMu(object $r): bool
    {
        return $r->kupon_toplam_adet !== null && (int) $r->kupon_dagitilan >= (int) $r->kupon_toplam_adet;
    }

    /** Salonun (şube grubu dahil) reklamı mı — grup yazıldıysa grup, yazılmadıysa kaynak salon. */
    public static function salonaUygulanirMi(object $r, int $salonId): bool
    {
        $grup = SalonIdListesi::coz($r->gecerli_salonlar ?? null);

        return $grup ? in_array($salonId, $grup, true) : (int) $r->salon_id === $salonId;
    }

    /**
     * Uygulama içi kart / açılış popup'ı bu müşteriye gösterilir mi.
     *
     * @param  int  $salonId  müşterinin salonu (0 = bundle ile soruldu; grup denetimi yapılmaz)
     * @param  int  $userId   0 = misafir (kişiye özel reklam gizlenir, kapma sayımı yapılmaz)
     * @param  int  $kaptigi  müşterinin bu reklamdan kaptığı kupon sayısı
     */
    public static function musteriyeGorunurMu(object $r, int $salonId, int $userId, int $kaptigi, CarbonInterface $simdi): bool
    {
        if (! ((bool) $r->kanal_inapp || (bool) $r->tam_ekran) || ! self::yayindaMi($r, $simdi)) {
            return false;
        }
        if ($salonId > 0 && ! self::salonaUygulanirMi($r, $salonId)) {
            return false;
        }

        // Kişiye özel (test / tekil) reklam yalnız o kişiye görünür.
        $hedef = HedefKosulu::dbden($r->hedef_kitle ?? null, $r->hedef_kosul ?? null);
        if ($hedef->kisiyeOzelMi() && ($userId <= 0 || $hedef->deger !== $userId)) {
            return false;
        }

        $aksiyon = ReklamAksiyonu::dbden($r->aksiyon_tipi ?? null);
        // Boş slot penceresinin son günü geçtiyse gizle.
        if ($aksiyon === ReklamAksiyonu::RANDEVU) {
            $son = $r->randevu_tarih_bit ?: $r->randevu_tarih;
            if ($son && $son < $simdi->toDateString()) {
                return false;
            }
        }

        // Salt-kupon reklamı: kişi limitini doldurana artık gösterme (randevu reklamı penceresi boyunca görünür).
        return ! ($userId > 0 && $aksiyon === ReklamAksiyonu::KUPON && $kaptigi >= self::kisiLimiti($r));
    }

    public static function kisiLimiti(object $r): int
    {
        return max(1, (int) ($r->kupon_kisi_limit ?? 1));
    }
}
