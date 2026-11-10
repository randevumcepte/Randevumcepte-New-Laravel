<?php

namespace App\Services\Randevu;

use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Models\Randevular;
use App\Support\BildirimKuyrugu;
use App\Support\KayitKodKapisi;
use App\Support\KodGonderimSiniri;
use App\Support\OnayKodu;
use App\Support\SmsAyari;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SMS ayari 16 — "geldi" isaretinde MUSTERI DOGRULAMA KODU. Ayar, istem metni ve kanal (WhatsApp oncelikli,
 * acil; gidemezse SMS) randevu geldi akisi (RandevuDurumServisi) ile ORTAK.
 *
 * Randevu geldi kodu randevu satirinda tutulur (kuyruk 'dogrulama_kodu', eski monolitle paylasilan akis; kolon ve
 * kuyruk sozlesmesi degismez). Kod TEK yerde uretilir (randevuKoduUret: OnayKodu::rastgele, rakam tekrarli 4 hane);
 * uretim zamani cache'te (randevu anahtari, kodun izi ile) tutulur ve kod 10 dk gecerlidir — cache kaydi yoksa ya da
 * kod baska yerde (eski monolit isleyicisi) yenilendiyse eski davranis (sure yok). Basarili dogrulamada kolon
 * temizlenir (tekrar kullanilamaz); randevu basina yanlis deneme siniri (RateLimiter, 10 dk'da 5 — dolunca kolon
 * null'lanir, kod oluru), gunluk yanlis siniri, yeniden gonderim beklemesi ve gunluk kod siniri (KayitKodKapisi; KVKK
 * onay koduyla ortak kapi).
 * Seans ve seansli hizmet 'geldi' isareti (PATCH/POST /seans) eskiden hic kod sormuyordu (eski mobilde kontrol
 * yorum satiriydi): burada kod OnayKodu ile musteriye ve kayda bagli, tek kullanimlik, 10 dk.
 */
class MusteriDogrulamaKodu
{
    public const SMS_AYARI = 16;

    public const ISTEM = 'Lütfen müşteri/danışanın telefon numarasına gönderilen doğrulama kodunu giriniz!';

    private const AMAC = 'seans_geldi';

    /** Randevu geldi kodunun gecerlilik suresi (OnayKodu ile ayni, 10 dk). */
    public const RANDEVU_KOD_SN = 600;

    /** Uretim kaydinin cache omru: sure denetimi icin yeter; dolunca eski davranisa duser. */
    private const URETIM_KAYDI_SN = 86400 * 7;

    public function gerekli(int $salonId): bool
    {
        return SmsAyari::musteri($salonId, self::SMS_AYARI);
    }

    /**
     * Seans kullanimini 'geldi' saymadan once: ayar aciksa kod yoksa musteriye gonderir ve 428 doner,
     * kod yanlissa 428 (kod_gonderildi=false). $baglam kaydi tanimlar (seans id ya da kalem + hizmet).
     */
    public function seansGeldi(int $salonId, ?int $musteriId, array $baglam, ?string $kod): void
    {
        if (! $this->gerekli($salonId)) {
            return;
        }
        if (! $musteriId) {
            throw new GecersizIslemException('Satışın müşterisi bulunamadı; doğrulama kodu gönderilemiyor.');
        }
        OnayKodu::zorunlu(self::AMAC, $salonId, $musteriId, $baglam, $kod, function (string $yeni) use ($salonId, $musteriId) {
            $tel = trim((string) DB::table('users')->where('id', $musteriId)->value('cep_telefon'));
            if ($tel === '') {
                throw new GecersizIslemException('Müşterinin telefonu kayıtlı değil; doğrulama kodu gönderilemiyor.');
            }
            BildirimKuyrugu::sms($salonId, null, [['to' => $tel, 'message' => self::mesaj($yeni)]], true);
        }, self::ISTEM);
    }

    /**
     * Randevu "geldi" kod kapisi (ayar 16 acik ve paket seansi var; karar cagiranda). Kod dogruysa null doner: cagiran
     * isaretler ve AYNI transaction'da randevuKoduTuket cagirir. Degilse hatali '2' cevabi:
     *   - kod bos: $yaz ise ve bekleme yoksa yeni kod istenir ($kodGonder kuyruga yazar), kod_gonderildi=true;
     *     beklemedeyse kod uretilmez, mesaja kalan sure eklenir;
     *   - kod yanlis: yanlis deneme sayilir (dry-run dahil: tahmin kahini olmasin), yeni kod GITMEZ.
     * Sinir asiminda 429.
     *
     * @param  callable():void  $kodGonder
     * @return array{hatali:string,mesaj:string,kod_gonderildi:bool}|null
     *
     * @throws CokFazlaIstekException
     */
    public function randevuKoduDenetle(Randevular $randevu, mixed $kod, bool $yaz, callable $kodGonder): ?array
    {
        $id = (int) $randevu->id;
        $gonderim = self::randevuGonderimAnahtari($id);
        $mevcut = $randevu->dogrulama !== null ? trim((string) $randevu->dogrulama) : '';
        $sonuc = KayitKodKapisi::dogrula($gonderim, self::randevuHataAnahtari($id), $mevcut, $kod,
            fn () => $this->randevuKodunuDusur($id, $mevcut));

        if ($sonuc === KayitKodKapisi::DOGRU) {
            if (! self::sureDoldu(Cache::get(self::uretimAnahtari($id)), $mevcut, now()->getTimestamp())) {
                return null;
            }
            $this->randevuKodunuDusur($id, $mevcut);

            return ['hatali' => '2', 'mesaj' => 'Doğrulama kodunun süresi doldu. Yeni kod isteyin.', 'kod_gonderildi' => false];
        }
        if ($sonuc === KayitKodKapisi::YANLIS) {
            return ['hatali' => '2', 'mesaj' => 'Doğrulama kodu hatalı. Müşteriye gönderilen kodu tekrar girin.', 'kod_gonderildi' => false];
        }

        if (! $yaz) {
            return ['hatali' => '2', 'mesaj' => self::ISTEM, 'kod_gonderildi' => false];
        }
        $kalan = KayitKodKapisi::gonder($gonderim, $kodGonder);
        if ($kalan > 0) {
            return ['hatali' => '2', 'mesaj' => self::ISTEM.' '.KodGonderimSiniri::bekleMesaji($kalan), 'kod_gonderildi' => false];
        }

        return ['hatali' => '2', 'mesaj' => self::ISTEM, 'kod_gonderildi' => true];
    }

    /**
     * Dogrulanan kodu tuketir (randevular.dogrulama = null). Kosullu guncelleme: es zamanli ikinci istek ayni kodla
     * gecemez. Cagiranin transaction'i icinde cagrilir.
     *
     * @throws GecersizIslemException kod bu arada kullanildi / degisti
     */
    public function randevuKoduTuket(int $randevuId, mixed $kod): void
    {
        $n = Randevular::where('id', $randevuId)->where('dogrulama', trim((string) $kod))->update(['dogrulama' => null]);
        if ($n === 0) {
            throw new GecersizIslemException('Doğrulama kodu kullanılmış ya da yenilenmiş. Lütfen yeni kod isteyin.');
        }
        KayitKodKapisi::basarili(self::randevuGonderimAnahtari($randevuId), self::randevuHataAnahtari($randevuId));
        Cache::forget(self::uretimAnahtari($randevuId));
    }

    /**
     * Randevu geldi kodunu URETIR — tek uretici (kuyruk isleyicisi DogrulamaKoduIsleyici cagirir). Kolon sozlesmesi
     * degismez (randevular.dogrulama duz 4 hane; eski monolit ayni kolonu okur); uretim zamani + kodun izi cache'e.
     */
    public function randevuKoduUret(Randevular $randevu): string
    {
        $kod = OnayKodu::rastgele(4);
        $randevu->dogrulama = $kod;
        $randevu->save();
        Cache::put(self::uretimAnahtari((int) $randevu->id), ['iz' => self::kodIzi($kod), 'zaman' => now()->getTimestamp()], self::URETIM_KAYDI_SN);

        return $kod;
    }

    /**
     * Saf: kodun 10 dk'si doldu mu? Kayit yoksa (eski isleyici uretti / kayit oldu) ya da kayit baska bir koda aitse
     * (kod sonradan eski kodla yenilendi) false — eski davranis, sure uygulanmaz.
     *
     * @param  array{iz?:string,zaman?:int}|null  $kayit
     */
    public static function sureDoldu(?array $kayit, string $kod, int $simdi): bool
    {
        if (! $kayit || ! isset($kayit['iz'], $kayit['zaman']) || ! hash_equals((string) $kayit['iz'], self::kodIzi($kod))) {
            return false;
        }

        return $simdi - (int) $kayit['zaman'] > self::RANDEVU_KOD_SN;
    }

    /** Kod oldu (sure / yanlis siniri): kolon null'lanir (yalniz hala ayni kodsa), bekleme kalkar — yeni kod istenebilir. */
    private function randevuKodunuDusur(int $randevuId, string $kod): void
    {
        Randevular::where('id', $randevuId)->where('dogrulama', $kod)->update(['dogrulama' => null]);
        Cache::forget(self::uretimAnahtari($randevuId));
        KodGonderimSiniri::serbestBirak(self::randevuGonderimAnahtari($randevuId));
    }

    private static function uretimAnahtari(int $randevuId): string
    {
        return 'randevu-geldi-kod-uretim:'.$randevuId;
    }

    private static function kodIzi(string $kod): string
    {
        return hash('sha256', 'randevu-geldi|'.$kod);
    }

    public static function randevuHataAnahtari(int $randevuId): string
    {
        return 'randevu-geldi-kod-hata:'.$randevuId;
    }

    public static function randevuGonderimAnahtari(int $randevuId): string
    {
        return 'randevu_geldi:'.$randevuId;
    }

    /** Randevu geldi koduyla ayni metin (DogrulamaKoduIsleyici). */
    public static function mesaj(string $kod): string
    {
        return 'Doğrulama kodunuz : '.$kod;
    }
}
