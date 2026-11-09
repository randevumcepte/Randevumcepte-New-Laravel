<?php

namespace App\Services\Randevu;

use App\Exceptions\CokFazlaIstekException;
use App\Support\KayitKodKapisi;
use App\Support\KodGonderimSiniri;
use App\Support\SmsAyari;
use Illuminate\Support\Facades\DB;

/**
 * SMS ayari 22 — randevu "geldi" isaretinde KVKK ONAY KODU (musteri_portfoy.onay_kodu; kolon eski monolitle ve
 * kuyruk isleyicisi KvkkKoduIsleyici ile paylasilir, duz 4 hane). Randevu geldi koduyla AYNI kapi (KayitKodKapisi):
 *   - hash_equals; portfoyde kod bossa hicbir kod gecmez (eskiden bos kod + bos giris geciyordu);
 *   - yanlis deneme dry-run dahil sayilir: portfoy basina 10 dk'da 5 (dolunca kod yenilenir, eski kod olur) ve
 *     gunluk yanlis siniri; yanlis kodda yeni kod GITMEZ (eskiden her denemede yeniden SMS gidiyordu);
 *   - gonderim (kod bos girilince kuyruga 'kvkk_kodu' — yeniden gonder de budur) yeniden gonderim beklemesi ve gunluk
 *     kod siniri ile; portfoyde kod yoksa gondermeden once uretilir.
 * Kod portfoye aittir (randevuya degil): sinir anahtarlari portfoy basina.
 */
class KvkkOnayKodu
{
    public const SMS_AYARI = 22;

    public const ISTEM = 'Lütfen müşteri/danışanın telefon numarasına gönderilen kvkk onay kodunu giriniz!';

    public const HATALI = 'KVKK onay kodu hatalı. Müşteriye gönderilen kodu tekrar girin.';

    /** @param  object{kvkk_onay_alindi:mixed}|null  $portfoy */
    public function gerekli(int $salonId, ?object $portfoy): bool
    {
        return $portfoy !== null && ! $portfoy->kvkk_onay_alindi && SmsAyari::musteri($salonId, self::SMS_AYARI);
    }

    /**
     * Kod dogruysa null (cagiran isaretler, kvkk_onay_alindi=1 yazar ve basarili() cagirir). Degilse hatali '3':
     *   - kod bos: $yaz ise ve bekleme yoksa kod gonderilir ($kodGonder kuyruga yazar), kod_gonderildi=true;
     *     beklemedeyse gonderilmez, mesaja kalan sure eklenir; dry-run hic gondermez;
     *   - kod yanlis: sayilir, yeni kod gitmez.
     *
     * @param  object{id:mixed,onay_kodu:mixed}  $portfoy
     * @param  callable():void  $kodGonder
     * @return array{hatali:string,mesaj:string,kod_gonderildi:bool}|null
     *
     * @throws CokFazlaIstekException
     */
    public function denetle(object $portfoy, mixed $kod, bool $yaz, callable $kodGonder): ?array
    {
        $id = (int) $portfoy->id;
        $mevcut = trim((string) ($portfoy->onay_kodu ?? ''));
        $gonderim = self::gonderimAnahtari($id);
        $sonuc = KayitKodKapisi::dogrula($gonderim, self::hataAnahtari($id), $mevcut, $kod, function () use ($id, $mevcut, $gonderim) {
            $this->kodYenile($id, $mevcut);
            KodGonderimSiniri::serbestBirak($gonderim); // olen kodun yerine hemen yeni kod istenebilir
        });

        if ($sonuc === KayitKodKapisi::DOGRU) {
            return null;
        }
        if ($sonuc === KayitKodKapisi::YANLIS) {
            return self::cevap(self::HATALI, false);
        }
        if (! $yaz) {
            return self::cevap(self::ISTEM, false);
        }
        $kalan = KayitKodKapisi::gonder($gonderim, function () use ($id, $mevcut, $kodGonder) {
            if ($mevcut === '') {
                $this->kodYenile($id, ''); // isleyici bos kodu gonderemez; once uret
            }
            $kodGonder();
        });

        return $kalan > 0
            ? self::cevap(self::ISTEM.' '.KodGonderimSiniri::bekleMesaji($kalan), false)
            : self::cevap(self::ISTEM, true);
    }

    /** Onay alindi: kisa pencere sayaci ve bekleme temizlenir. */
    public function basarili(int $portfoyId): void
    {
        KayitKodKapisi::basarili(self::gonderimAnahtari($portfoyId), self::hataAnahtari($portfoyId));
    }

    /** Portfoy KVKK onay kodu — TEK uretici (yeni portfoy satirlari ve yenileme); eski bicim: 1000-9999. */
    public static function yeniKod(): string
    {
        return (string) random_int(1000, 9999);
    }

    /** Kodu yenile (yalniz hala $mevcut ise: es zamanli yenilemeyi ezmez); yeni kod olen koddan farklidir. */
    private function kodYenile(int $portfoyId, string $mevcut): void
    {
        do {
            $yeni = self::yeniKod();
        } while ($yeni === $mevcut);
        DB::table('musteri_portfoy')->where('id', $portfoyId)
            ->where(fn ($q) => $mevcut === '' ? $q->whereNull('onay_kodu')->orWhere('onay_kodu', '') : $q->where('onay_kodu', $mevcut))
            ->update(['onay_kodu' => $yeni]);
    }

    private static function cevap(string $mesaj, bool $gonderildi): array
    {
        return ['hatali' => '3', 'mesaj' => $mesaj, 'kod_gonderildi' => $gonderildi];
    }

    public static function gonderimAnahtari(int $portfoyId): string
    {
        return 'kvkk_onay:'.$portfoyId;
    }

    public static function hataAnahtari(int $portfoyId): string
    {
        return 'kvkk-onay-kod-hata:'.$portfoyId;
    }
}
