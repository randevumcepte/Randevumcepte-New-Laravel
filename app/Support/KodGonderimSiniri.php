<?php

namespace App\Support;

use App\Exceptions\CokFazlaIstekException;
use Illuminate\Support\Facades\Cache;

/**
 * Onay / dogrulama kodu GONDERIM ve DENEME sinirlari — TEK kaynak (OnayKodu::uretSinirli / dogrulaSinirli: silmede
 * hesap sahibi onayi, seans geldi, kasa para cikisi; randevu geldi kodu: RandevuDurumServisi).
 *
 * Anahtar basina (cagiran verir; or. "v2_onay_kodu:<amac>:<salon>:<sahip>" ya da "randevu_geldi:<id>"):
 *   - yeniden gonderim beklemesi (config oturum.onay_kodu.bekleme_sn, 60): bu surede yeni kod uretilmez/gonderilmez;
 *     cagiran ayri bir bekleme anahtari verebilir (OnayKodu: islem/kayit basina) — gunluk sayaclar yine $anahtar'da;
 *   - gunluk kod siniri (gunluk_kod, 5): 24 saatte en cok bu kadar kod; asilirsa 429;
 *   - gunluk yanlis deneme siniri (gunluk_hata, 10): yeni kod uretilince SIFIRLANMAZ (kaba kuvvet her yeni kodda
 *     bastan baslamaz). Siniri uygulamak cagiranin isidir (hataDoldu).
 * Sayaclar Cache::add + Cache::increment ile atomiktir (database / redis / array store'da yaris yok).
 */
final class KodGonderimSiniri
{
    // Sayaclar ilk kayittan itibaren 24 saat yasar (takvim gunu degil): metin pencereyle uyumlu.
    public const GUNLUK_KOD_MESAJI = 'Bu işlem için son 24 saatte çok fazla kod istendi. Lütfen daha sonra tekrar deneyin.';

    public const GUNLUK_HATA_MESAJI = 'Son 24 saatte çok fazla hatalı kod girildi. Lütfen daha sonra tekrar deneyin.';

    private const GUN_SN = 86400;

    /**
     * Gonderim hakki al. 0: gonderilebilir (bekleme baslar, gunluk sayac artar); >0: bekleme suresinde, kalan saniye
     * (cagiran yeni kod URETMEZ, bekleMesaji ile cevap verir).
     *
     * @throws CokFazlaIstekException gunluk kod siniri doldu
     */
    public static function hakAl(string $anahtar, ?string $beklemeAnahtari = null): int
    {
        $beklemeAnahtari ??= $anahtar;
        $kalan = self::kalanSn($beklemeAnahtari);
        if ($kalan > 0) {
            return $kalan;
        }
        if ((int) Cache::get(self::gunAnahtari($anahtar), 0) >= self::ayar('gunluk_kod', 5)) {
            throw new CokFazlaIstekException(self::GUNLUK_KOD_MESAJI);
        }
        $bekleme = self::ayar('bekleme_sn', 60);
        if (! Cache::add(self::bekleAnahtari($beklemeAnahtari), now()->getTimestamp() + $bekleme, $bekleme)) {
            return max(1, self::kalanSn($beklemeAnahtari)); // es zamanli istek hakki aldi
        }
        self::say(self::gunAnahtari($anahtar));

        return 0;
    }

    /** Yeniden gonderime kalan saniye (0: bekleme yok). */
    public static function kalanSn(string $anahtar): int
    {
        return max(0, (int) Cache::get(self::bekleAnahtari($anahtar), 0) - now()->getTimestamp());
    }

    /** Bekleyen kod kullanildi ya da gonderilemedi: sonraki islem beklemeden yeni kod isteyebilir. */
    public static function serbestBirak(string $anahtar): void
    {
        Cache::forget(self::bekleAnahtari($anahtar));
    }

    public static function hataSay(string $anahtar): void
    {
        self::say(self::hataAnahtari($anahtar));
    }

    public static function hataDoldu(string $anahtar): bool
    {
        return (int) Cache::get(self::hataAnahtari($anahtar), 0) >= self::ayar('gunluk_hata', 10);
    }

    public static function bekleMesaji(int $sn): string
    {
        return 'Kod gönderildi. Yeni kod için '.$sn.' sn sonra tekrar deneyin.';
    }

    private static function say(string $anahtar): void
    {
        Cache::add($anahtar, 0, self::GUN_SN);
        Cache::increment($anahtar);
    }

    private static function ayar(string $alan, int $varsayilan): int
    {
        return (int) config('oturum.onay_kodu.'.$alan, $varsayilan);
    }

    private static function bekleAnahtari(string $anahtar): string
    {
        return 'v2_kod_bekle:'.$anahtar;
    }

    private static function gunAnahtari(string $anahtar): string
    {
        return 'v2_kod_gun:'.$anahtar;
    }

    private static function hataAnahtari(string $anahtar): string
    {
        return 'v2_kod_hata:'.$anahtar;
    }
}
