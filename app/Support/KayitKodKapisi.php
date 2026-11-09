<?php

namespace App\Support;

use App\Exceptions\CokFazlaIstekException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Kayitta DUZ tutulan musteri kodlari (randevu geldi: randevular.dogrulama; KVKK: musteri_portfoy.onay_kodu) icin
 * ORTAK dogrulama ve gonderim kapisi. Kolon sozlesmesi eski monolitle paylasildigi icin kod hash'lenemez; koruma
 * burada:
 *   - karsilastirma hash_equals, kayittaki kod bossa HICBIR kod gecmez (bos == bos acigi yok);
 *   - yanlis deneme dry-run dahil sayilir (tahmin kahini olmasin): kayit basina kisa pencere (RateLimiter, config
 *     oturum.onay_kodu.randevu_hata / randevu_hata_sn: 10 dk'da 5) — dolunca $dusur ile kod oldurulur — ve gunluk
 *     yanlis siniri (KodGonderimSiniri::hataSay / hataDoldu; yeni kodla sifirlanmaz);
 *   - gonderim KodGonderimSiniri::hakAl ile: yeniden gonderim beklemesi ve gunluk kod siniri.
 * Sinir asiminda 429 (CokFazlaIstekException).
 */
final class KayitKodKapisi
{
    public const DOGRU = 'dogru';

    public const YANLIS = 'yanlis';

    /** Kod girilmedi: cagiran (yazma modundaysa) gonder() ile yeni kod ister. */
    public const BOS = 'bos';

    /**
     * @param  string  $anahtar       gonderim + gunluk sayac anahtari (KodGonderimSiniri)
     * @param  string  $hataAnahtari  kisa pencere yanlis deneme anahtari (RateLimiter)
     * @param  string  $mevcut        kayittaki kod ('' = yok)
     * @param  callable():void|null  $dusur  kisa pencere siniri dolunca kodu oldurur (kayit hala ayni koddaysa)
     *
     * @throws CokFazlaIstekException
     */
    public static function dogrula(string $anahtar, string $hataAnahtari, string $mevcut, mixed $kod, ?callable $dusur = null): string
    {
        if (KodGonderimSiniri::hataDoldu($anahtar)) {
            throw new CokFazlaIstekException(KodGonderimSiniri::GUNLUK_HATA_MESAJI);
        }
        $kod = trim((string) $kod);
        if ($kod === '') {
            return self::BOS;
        }
        $sinir = self::ayar('randevu_hata', 5);
        if (RateLimiter::tooManyAttempts($hataAnahtari, $sinir)) {
            throw new CokFazlaIstekException('Çok fazla hatalı doğrulama kodu girildi. Lütfen '.max(1, (int) ceil(RateLimiter::availableIn($hataAnahtari) / 60)).' dakika sonra tekrar deneyin.');
        }
        $mevcut = trim($mevcut);
        if (self::eslesir($mevcut, $kod)) {
            return self::DOGRU;
        }
        RateLimiter::hit($hataAnahtari, self::ayar('randevu_hata_sn', 600));
        KodGonderimSiniri::hataSay($anahtar);
        if ($mevcut !== '' && $dusur !== null && RateLimiter::tooManyAttempts($hataAnahtari, $sinir)) {
            $dusur(); // kaba kuvvet: kod olur, yeni kod istenmeli
        }

        return self::YANLIS;
    }

    /** Saf: kayittaki kod dolu ve girilenle sabit zamanda ayni mi? */
    public static function eslesir(string $mevcut, string $kod): bool
    {
        $mevcut = trim($mevcut);
        $kod = trim($kod);

        return $mevcut !== '' && $kod !== '' && hash_equals($mevcut, $kod);
    }

    /**
     * Gonderim hakki alinirsa $kodGonder cagrilir (kuyruga yazar). 0: gonderildi; >0: bekleme suresinde, kalan sn
     * (kod gitmez; cagiran KodGonderimSiniri::bekleMesaji ile cevaplar). $kodGonder istisna atarsa bekleme kalkar.
     *
     * @param  callable():void  $kodGonder
     *
     * @throws CokFazlaIstekException gunluk kod siniri
     */
    public static function gonder(string $anahtar, callable $kodGonder): int
    {
        $kalan = KodGonderimSiniri::hakAl($anahtar);
        if ($kalan > 0) {
            return $kalan;
        }
        try {
            $kodGonder();
        } catch (\Throwable $e) {
            KodGonderimSiniri::serbestBirak($anahtar);
            throw $e;
        }

        return 0;
    }

    /** Kod basariyla kullanildi: kisa pencere sayaci temizlenir, bekleme kalkar (gunluk sayaclar kalir). */
    public static function basarili(string $anahtar, string $hataAnahtari): void
    {
        RateLimiter::clear($hataAnahtari);
        KodGonderimSiniri::serbestBirak($anahtar);
    }

    private static function ayar(string $alan, int $varsayilan): int
    {
        return (int) config('oturum.onay_kodu.'.$alan, $varsayilan);
    }
}
