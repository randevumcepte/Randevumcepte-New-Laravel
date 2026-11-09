<?php

namespace App\Support;

use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\OnayKoduGerekliException;
use Illuminate\Support\Facades\Cache;

/**
 * Tek kullanimlik SMS onay kodu (kasadan para cekme, kasadaki tahsilati silme vb.).
 *
 * Eski kodun aciklari kapatildi: kod isletme_yetkilileri / tahsilatlar tablosuna duz
 * yaziliyor, hic temizlenmiyor (tekrar kullanilabiliyordu), gevsek (==) karsilastiriliyor
 * ve hic uretilmemisse BOS kod kabul ediliyordu. Burada: rastgele 4 hane, yalniz hash'i
 * saklanir, islem baglamina (tutar, kayit id) baglidir, 10 dk gecerli, 5 yanlis denemede
 * duser, dogru kullanimda silinir. Tuketim atomiktir: dogru kodla es zamanli iki istekten yalniz biri gecer
 * (kod basina tek seferlik Cache::add talebi).
 *
 * Kaba kuvvet ve mesaj bombardimani (uretSinirli / dogrulaSinirli; zorunlu ve kasa para cikisi bunlari kullanir):
 * islem (baglam) basina 60 sn yeniden gonderim beklemesi, anahtar basina 24 saatte en cok 5 kod ve toplam yanlis
 * deneme siniri (KodGonderimSiniri). Baska islemin kodu girilirse yanlis sayilmaz, "bu kod başka işlem için" doner. Giris / sifre / deneme kodlari kendi telefon + IP sinirlariyla uret / dogrula'yi dogrudan
 * kullanir (gunluk yanlis siniri orada uygulanmaz: baskasi numaraya yanlis kod girerek sahibini kilitleyemesin).
 */
final class OnayKodu
{
    private const SURE_SN = 600;

    private const MAKS_DENEME = 5;

    /**
     * Rastgele N haneli sayisal kod — TEK uretici (onay kodlari + randevu geldi kodu). random_int (kriptografik),
     * rakam tekrarina izin verir (0000-9999 = 10^4 olasilik; eski str_shuffle tekrarsiz 5040'ti).
     */
    public static function rastgele(int $hane = 4): string
    {
        return str_pad((string) random_int(0, 10 ** $hane - 1), $hane, '0', STR_PAD_LEFT);
    }

    /** Kod dogru ama BASKA bir islem (kayit / tutar) icin uretilmis: yanlis deneme sayilmaz, kod tuketilmez. */
    public const BASKA_ISLEM_MESAJI = 'Bu kod başka bir işlem için gönderildi. Bu işlem için yeni kod isteyin.';

    /** dogrulaSonuc() cevaplari. */
    public const DOGRU = 'dogru';

    public const YANLIS = 'yanlis';

    public const BASKA_ISLEM = 'baska_islem';

    /** Yeni kod uretir (oncekini gecersiz kilar) ve SMS'e konacak duz kodu dondurur. */
    public static function uret(string $amac, int $salonId, int $yetkiliId, array $baglam = [], int $hane = 4, int $sureSn = self::SURE_SN): string
    {
        $kod = self::rastgele($hane);
        $tek = bin2hex(random_bytes(8));
        Cache::put(self::anahtar($amac, $salonId, $yetkiliId), [
            'hash'   => hash('sha256', $kod.'|'.self::baglamIzi($baglam)),
            // Baglamdan bagimsiz kod izi + baglam izi: dogru kodun baska islemde girildigini ayirt etmek icin.
            'kod'    => hash('sha256', $kod.'|'.$tek),
            'baglam' => self::baglamHash($baglam),
            'deneme' => 0,
            'bitis'  => time() + $sureSn,
            'tek'    => $tek,
        ], $sureSn);

        return $kod;
    }

    /**
     * Sinirli uretim: gunluk yanlis siniri doluysa 429; ayni islem (baglam) icin yeniden gonderim beklemesindeyse yeni
     * kod URETILMEZ ve 428 ("Kod gönderildi, X sn sonra tekrar"; onceki kod gecerli kalir); gunluk kod siniri doluysa
     * 429. Bekleme baglama ozeldir: baska kayit icin istekte yalniz gunluk kod siniri uygulanir. Bekleyen kod baska bir
     * islem icinse (o kod bu baglaminkinin yerini aldi) bu baglamin beklemesi kalkar — kodsuz beklemede kalinmaz.
     *
     * @throws OnayKoduGerekliException|CokFazlaIstekException
     */
    public static function uretSinirli(string $amac, int $salonId, int $sahipId, array $baglam = [], int $hane = 4): string
    {
        $anahtar = self::anahtar($amac, $salonId, $sahipId);
        if (KodGonderimSiniri::hataDoldu($anahtar)) {
            throw new CokFazlaIstekException(KodGonderimSiniri::GUNLUK_HATA_MESAJI);
        }
        $bekleme = self::beklemeAnahtari($anahtar, $baglam);
        $bekleyen = Cache::get($anahtar);
        if (is_array($bekleyen) && isset($bekleyen['baglam']) && ! hash_equals((string) $bekleyen['baglam'], self::baglamHash($baglam))) {
            KodGonderimSiniri::serbestBirak($bekleme);
        }
        $kalan = KodGonderimSiniri::hakAl($anahtar, $bekleme);
        if ($kalan > 0) {
            throw new OnayKoduGerekliException(KodGonderimSiniri::bekleMesaji($kalan), false);
        }

        return self::uret($amac, $salonId, $sahipId, $baglam, $hane);
    }

    /**
     * Ortak "uret, gonder; gonderilemezse iptal et ve beklemeyi birak" adimi (zorunlu ve kasa para cikisi). $gonder duz
     * kodu alip mesaji kuyruga yazar; istisna atarsa kod silinir, bekleme kalkar (alici yokken kullanici kilitlenmez)
     * ve istisna aynen yukari gider.
     *
     * @param  callable(string):void  $gonder
     *
     * @throws OnayKoduGerekliException|CokFazlaIstekException
     */
    public static function uretVeGonder(string $amac, int $salonId, int $sahipId, array $baglam, callable $gonder, int $hane = 4): void
    {
        $yeni = self::uretSinirli($amac, $salonId, $sahipId, $baglam, $hane);
        try {
            $gonder($yeni);
        } catch (\Throwable $e) {
            self::iptal($amac, $salonId, $sahipId, $baglam);
            throw $e;
        }
    }

    /**
     * Sinirli dogrulama: gunluk yanlis siniri doluysa 429 (dogru kod da gecmez). Dogru kodda bu islemin yeniden
     * gonderim beklemesi kalkar (sonraki islem hemen kod isteyebilir). Kod dogru ama baska islem icinse 428
     * (BASKA_ISLEM_MESAJI; yanlis sayilmaz, kod tuketilmez).
     *
     * @throws CokFazlaIstekException|OnayKoduGerekliException
     */
    public static function dogrulaSinirli(string $amac, int $salonId, int $sahipId, ?string $kod, array $baglam = []): bool
    {
        $anahtar = self::anahtar($amac, $salonId, $sahipId);
        if (KodGonderimSiniri::hataDoldu($anahtar)) {
            throw new CokFazlaIstekException(KodGonderimSiniri::GUNLUK_HATA_MESAJI);
        }
        $sonuc = self::dogrulaSonuc($amac, $salonId, $sahipId, $kod, $baglam);
        if ($sonuc === self::BASKA_ISLEM) {
            throw new OnayKoduGerekliException(self::BASKA_ISLEM_MESAJI, false);
        }
        if ($sonuc !== self::DOGRU) {
            return false;
        }
        KodGonderimSiniri::serbestBirak(self::beklemeAnahtari($anahtar, $baglam));

        return true;
    }

    /** Kod gonderilemedi (alici yok, kuyruk hatasi): kod silinir, bu islemin beklemesi kalkar. */
    private static function iptal(string $amac, int $salonId, int $sahipId, array $baglam): void
    {
        $anahtar = self::anahtar($amac, $salonId, $sahipId);
        Cache::forget($anahtar);
        KodGonderimSiniri::serbestBirak(self::beklemeAnahtari($anahtar, $baglam));
    }

    /** Bu baglam icin gecerli bir kod var mi (tani icin; kodu tuketmez). */
    public static function bekliyorMu(string $amac, int $salonId, int $yetkiliId): bool
    {
        return Cache::has(self::anahtar($amac, $salonId, $yetkiliId));
    }

    /** Kod dogru ve baglam ayniysa true (ve kod tuketilir); bkz. dogrulaSonuc. */
    public static function dogrula(string $amac, int $salonId, int $yetkiliId, ?string $kod, array $baglam = []): bool
    {
        return self::dogrulaSonuc($amac, $salonId, $yetkiliId, $kod, $baglam) === self::DOGRU;
    }

    /**
     * DOGRU: kod dogru ve baglam ayni (kod tuketilir; tuketim atomik: ayni kod icin yalniz ilk Cache::add talebi
     * kazanir). BASKA_ISLEM: kod dogru ama baska baglam icin (sayac artmaz, kod kalir). YANLIS: kodun deneme sayaci ve
     * anahtarin gunluk yanlis sayaci artar.
     */
    public static function dogrulaSonuc(string $amac, int $salonId, int $yetkiliId, ?string $kod, array $baglam = []): string
    {
        $anahtar = self::anahtar($amac, $salonId, $yetkiliId);
        $kayit = Cache::get($anahtar);
        $kod = trim((string) $kod);
        if (! $kayit || $kod === '') {
            return self::YANLIS;
        }
        if (hash_equals($kayit['hash'], hash('sha256', $kod.'|'.self::baglamIzi($baglam)))) {
            $kalan = max(1, (int) ($kayit['bitis'] ?? 0) - time());
            if (! Cache::add('v2_onay_kodu_tuket:'.($kayit['tek'] ?? $kayit['hash']), 1, $kalan)) {
                return self::YANLIS; // es zamanli istek bu kodu az once tuketti
            }
            Cache::forget($anahtar);

            return self::DOGRU;
        }
        if (self::baskaIslemIcin($kayit, $kod, self::baglamHash($baglam))) {
            return self::BASKA_ISLEM;
        }
        KodGonderimSiniri::hataSay($anahtar);
        $kayit['deneme']++;
        // Yanlis deneme kodun omrunu uzatmaz.
        $kalan = (int) ($kayit['bitis'] ?? 0) - time();
        $kayit['deneme'] >= self::MAKS_DENEME || $kalan <= 0 ? Cache::forget($anahtar) : Cache::put($anahtar, $kayit, $kalan);

        return self::YANLIS;
    }

    /**
     * Saf: kayittaki kod girilen kodla ayni ama baglami farkli mi? (Eski bicimli kayitta — 'kod'/'baglam' yok — false.)
     *
     * @param  array<string,mixed>  $kayit
     */
    public static function baskaIslemIcin(array $kayit, string $kod, string $baglamHash): bool
    {
        if (! isset($kayit['kod'], $kayit['baglam'], $kayit['tek'])) {
            return false;
        }

        return ! hash_equals((string) $kayit['baglam'], $baglamHash)
            && hash_equals((string) $kayit['kod'], hash('sha256', $kod.'|'.$kayit['tek']));
    }

    /**
     * Iki adimli kodlu islemin ORTAK kapisi (silmede hesap sahibi onayi, seans geldi musteri kodu, kasa...):
     *   - kod bos: yeni kod uretilir, $gonder ile aliciya yollanir, 428 (OnayKoduGerekliException) doner; ayni islem
     *     icin yeniden gonderim beklemesindeyse kod uretilmez, 428 "Kod gönderildi, X sn sonra tekrar"; gunluk
     *     sinirlar 429;
     *   - kod dolu: dogrulanir (tek kullanimlik, baglama bagli); yanlissa 428 kod_gonderildi=false; baska islemin
     *     koduysa 428 BASKA_ISLEM_MESAJI (yanlis sayilmaz).
     * Gecerse sessizce doner ve cagiran islemi yapar. Kod/mesaj uretimi cagiranin transaction'i DISINDA olmali.
     *
     * @param  callable(string):void  $gonder  duz kodu alip mesaji kuyruga yazar
     *
     * @throws OnayKoduGerekliException|CokFazlaIstekException
     */
    public static function zorunlu(string $amac, int $salonId, int $sahipId, array $baglam, ?string $kod, callable $gonder, string $istemMesaji, int $hane = 4): void
    {
        if (trim((string) $kod) === '') {
            self::uretVeGonder($amac, $salonId, $sahipId, $baglam, $gonder, $hane);

            throw new OnayKoduGerekliException($istemMesaji);
        }
        if (! self::dogrulaSinirli($amac, $salonId, $sahipId, $kod, $baglam)) {
            throw new OnayKoduGerekliException('Kod hatalı veya süresi doldu. Yeni kod isteyebilirsiniz.', false);
        }
    }

    private static function anahtar(string $amac, int $salonId, int $yetkiliId): string
    {
        return "v2_onay_kodu:{$amac}:{$salonId}:{$yetkiliId}";
    }

    /** Yeniden gonderim beklemesi islem (baglam) basina; gunluk sayaclar $anahtar uzerinde ortak kalir. */
    private static function beklemeAnahtari(string $anahtar, array $baglam): string
    {
        return $anahtar.':'.substr(self::baglamHash($baglam), 0, 16);
    }

    private static function baglamIzi(array $baglam): string
    {
        ksort($baglam);

        return json_encode($baglam);
    }

    private static function baglamHash(array $baglam): string
    {
        return hash('sha256', self::baglamIzi($baglam));
    }
}
