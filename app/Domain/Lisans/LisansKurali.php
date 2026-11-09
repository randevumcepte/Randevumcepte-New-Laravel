<?php

namespace App\Domain\Lisans;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Salon lisansi (salonlar.uyelik_bitis_tarihi) — saf kural, web ve mobil kapisinin tek kaynagi.
 *
 * Eski panelle ayni: lisans bitis gununun 23:59:59'una kadar acik, ertesi gun 00:00'da kapanir
 * (eski lisans_sure_kontrol: round((bitis 23:59:59 - simdi) / gun) '-' iceriyorsa lisanssurebitti).
 * Tarih bos / gecersiz / 0000-... ise lisans henuz aktif degildir ve panel kapalidir. Gun sinirini
 * uygulamanin saat diliminde (Europe/Istanbul) hesaplar; sunucu saati UTC olsa da gece yarisi kaymaz.
 */
final class LisansKurali
{
    /** Istemcilerin (web v2-ortak.js, Flutter ApiIstemci) yakaladigi makine okunur kod. */
    public const KOD = 'lisans_suresi_doldu';

    /** Kapali lisans cevabi: 402 Payment Required (403 yetki/kapsam ile karismaz). */
    public const HTTP_KODU = 402;

    /** Bitis gunu (Y-m-d); tarih yok ya da gecersizse null. Saat kismi yok sayilir. */
    public static function bitisGunu(mixed $tarih): ?string
    {
        $s = trim((string) ($tarih ?? ''));
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) || (int) $m[1] === 0) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? substr($s, 0, 10) : null;
    }

    public static function acikMi(mixed $tarih, DateTimeInterface $simdi, string $saatDilimi): bool
    {
        $gun = self::bitisGunu($tarih);

        return $gun !== null && $gun >= self::bugun($simdi, $saatDilimi);
    }

    /** Kullaniciya gosterilen ozel mesaj (eski lisanssurebitti ekraniyla ayni anlam). */
    public static function mesaj(mixed $tarih): string
    {
        $gun = self::bitisGunu($tarih);
        if ($gun === null) {
            return 'Panel kullanımı için lisansınız henüz aktif olmamıştır. İşletmeniz için uygun paketi satın alarak paneli kullanmaya başlayabilirsiniz.';
        }

        return 'Panel kullanım süreniz '.implode('.', array_reverse(explode('-', $gun))).' tarihinde sona ermiştir. Paketinizi yenileyerek paneli kullanmaya devam edebilirsiniz.';
    }

    /**
     * 402 cevabinin veri alani. $salonId null: istek belirli bir bagli salona gitmiyor (salonsuz ya da bagli olmayan
     * salonlu istek); hangi salon oldugu soylenmez, istemci acik paneli kapatir.
     *
     * @return array{kod:string,salon_id:?int,uyelik_bitis_tarihi:?string,aktif_olmadi:bool}
     */
    public static function cevapVerisi(?int $salonId, mixed $tarih): array
    {
        $gun = self::bitisGunu($tarih);

        return ['kod' => self::KOD, 'salon_id' => $salonId, 'uyelik_bitis_tarihi' => $gun, 'aktif_olmadi' => $gun === null];
    }

    /**
     * Istek kapali bir salona mi gidiyor? (lisans kapisinin saf karari; veriyi LisansServisi okur)
     *   - Istenen salonlardan kullaniciya BAGLI olanlarin her biri acik olmali; kapali olan varsa o salonla kapali.
     *   - Istek salon belirtmiyorsa ya da belirttigi salonlardan biri bagli degilse (kayit id'li uc, istekteki
     *     salon_id ile oynama): kullanicinin bagli salonlarinin HEPSI kapaliysa kapali, salon_id null (baska salonun
     *     lisans durumu sizdirilmaz, hangi salon oldugu soylenmez). Acik bir salonu varsa gecer; kaydin salonu
     *     kapsam gecidinde (GuvenlikKapisi::kapsamDenetle) ayrica denetlenir.
     *
     * @param  int[]  $bagli  kullanicinin erisebildigi salonlar
     * @param  int[]  $istenenler  istekteki salon alanlari (salon_id / sube / salonid), tekrarsiz
     * @param  array<int,mixed>  $bitisler  salon id => uyelik_bitis_tarihi
     * @return array{kod:string,salon_id:?int,uyelik_bitis_tarihi:?string,aktif_olmadi:bool}|null
     */
    public static function kapaliSalon(array $bagli, array $istenenler, array $bitisler, DateTimeInterface $simdi, string $saatDilimi): ?array
    {
        $acik = fn (int $id) => self::acikMi($bitisler[$id] ?? null, $simdi, $saatDilimi);
        foreach (array_intersect($istenenler, $bagli) as $id) {
            if (! $acik($id)) {
                return self::cevapVerisi($id, $bitisler[$id] ?? null);
            }
        }
        $bagliOlmayanVar = $istenenler === [] || array_diff($istenenler, $bagli) !== [];
        if (! $bagliOlmayanVar || $bagli === []) {
            return null;
        }
        foreach ($bagli as $id) {
            if ($acik($id)) {
                return null;
            }
        }

        return self::cevapVerisi(null, self::enGecBitis(array_map(fn ($id) => $bitisler[$id] ?? null, $bagli)));
    }

    /** Tarihlerden en gec bitis gunu (Y-m-d); hicbiri gecerli degilse null. */
    public static function enGecBitis(array $tarihler): ?string
    {
        $gunler = array_filter(array_map(self::bitisGunu(...), $tarihler));

        return $gunler ? max($gunler) : null;
    }

    private static function bugun(DateTimeInterface $simdi, string $saatDilimi): string
    {
        return DateTimeImmutable::createFromInterface($simdi)->setTimezone(new DateTimeZone($saatDilimi))->format('Y-m-d');
    }
}
