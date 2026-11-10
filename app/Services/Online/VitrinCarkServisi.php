<?php

namespace App\Services\Online;

use App\Domain\Cark\DilimTipi;
use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Services\MusteriHesap\MusteriCarkServisi;
use App\Services\MusteriHesap\MusteriOturumServisi;
use App\Services\Oturum\PlatformMesaj;
use App\Support\OnayKodu;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Vitrin carki, MISAFIR yolu (eski CarkifelekMusteriController cevir / smsKodGonder / smsKodDogrula). Giris yapmis
 * musteri /musteri-uygulama/cark uclarini kullanir; odul yazimi iki yolda da MusteriCarkServisi'nden gecer.
 *
 *   1. cevir: kazanan sunucuda secilir, HICBIR SEY YAZILMAZ. Odul varsa sonuc onbellekte taslak olarak saklanir
 *      (istemciye yalniz rastgele taslak anahtari gider; dilim istemciden alinmaz).
 *   2. kodGonder: ad, soyad, telefon; musteri kaydiyla AYNI kapi (bot + IP + numara siniri), kod OnayKodu ile uretilir
 *      (taslaga bagli, tek kullanimlik, 10 dk; numara basina gonderim beklemesi, gunluk kod ve yanlis deneme siniri) ve
 *      PlatformMesaj::kod ile gider (salonun WhatsApp'i, salonun SMS'i, platform hatti).
 *   3. kodDogrula: kod dogruysa taslak bir kez tuketilir; musteri bulunur ya da acilir, odul uyeninkiyle ayni kuralla
 *      (aralik kurali dahil: bu numara yakinda cevirdiyse reddedilir) yazilir. Yeni hesaba oturum verilir.
 *
 * Eskiden bilincli farklar: misafir siniri oturum cerezi yerine IP basina (cerez silinerek sinirsiz cevrilemez); odul
 * telefona bagli aralik kuralina tabi (eskide misafir hediyesi uyenin gunluk hakkini saymiyordu); kod yaniti hicbir
 * durumda kodu icermez (eski dev_kod); kayitli numaraya web'de kodla oturum verilmez (odul hesaba yazilir, musteri
 * sifresiyle girer).
 */
class VitrinCarkServisi
{
    private const TASLAK_SN = 1800;

    private const KOD_AMAC = 'cark_misafir';

    public function __construct(
        private MusteriCarkServisi $cark,
        private MusteriOturumServisi $oturum,
        private OnlineSalonKapisi $kapi,
        private PlatformMesaj $mesaj,
    ) {}

    /**
     * @return array{dilimIndex:int, dilim:array, odulKodu:null, kayitGerekli:bool, taslak:?string, kalanHak:int, sonrakiHakTarihi:?string}
     *
     * @throws CokFazlaIstekException IP'nin bu salondaki cevirme hakki doldu
     */
    public function cevir(int $salonId, string $ip): array
    {
        $this->kapi->salon($salonId);
        $anahtar = 'cark-misafir-cevir:'.$salonId.'|'.$ip;
        if (RateLimiter::tooManyAttempts($anahtar, (int) config('online.cark_misafir_ip'))) {
            throw new CokFazlaIstekException('Çarkı çevirdiniz. Bir sonraki çevirme: '.now()->addSeconds(RateLimiter::availableIn($anahtar))->format('d.m.Y').'.');
        }
        $secim = $this->cark->misafirSec($salonId);
        $tekrar = $secim['tip'] === DilimTipi::TEKRAR; // "tekrar dene" hak harcamaz (uye kuraliyla ayni)
        $sonraki = Carbon::today()->addDays($secim['aralik']);
        if (! $tekrar) {
            RateLimiter::hit($anahtar, max(1, (int) now()->diffInSeconds($sonraki, true)));
        }

        $taslak = null;
        if ($secim['odullu']) {
            $taslak = Str::random(40);
            Cache::put(self::taslakAnahtari($taslak), ['salon_id' => $salonId, 'cark_id' => $secim['cark_id'], 'dilim_id' => $secim['dilim_id']], self::TASLAK_SN);
        }

        return $secim['sonuc'] + [
            'odulKodu'         => null,
            'kayitGerekli'     => $taslak !== null,
            'taslak'           => $taslak,
            'kalanHak'         => 0,
            'sonrakiHakTarihi' => $tekrar ? null : $sonraki->toDateString(),
        ];
    }

    /**
     * @param  array{taslak:string, ad:string, soyad:string, cep_telefon:string, _hp?:?string, form_sure_sn?:int|string|null}  $g
     * @return array{telefon:string}
     */
    public function kodGonder(int $salonId, array $g, string $ip): array
    {
        $taslak = $this->taslak($salonId, $g['taslak']);
        $tel = $this->oturum->kayitKapisi($g, $ip);
        $salon = $this->kapi->salon($salonId);
        Cache::put(self::taslakAnahtari($g['taslak']), [
            'ad_soyad' => trim(trim($g['ad']).' '.trim($g['soyad'])), 'telefon' => $tel,
        ] + $taslak, self::TASLAK_SN);

        OnayKodu::uretVeGonder(self::kodAmaci($tel), $salonId, 0, ['taslak' => $g['taslak']], function (string $kod) use ($tel, $salon, $salonId) {
            if (! $this->mesaj->kod($tel, $salon->salon_adi.' çarkıfelek doğrulama kodunuz: '.$kod, 'Dogrulama', $salonId)) {
                throw new GecersizIslemException('Kod gönderilemedi. Lütfen daha sonra tekrar deneyin.');
            }
        });

        return ['telefon' => $tel];
    }

    /**
     * @return array cevir() cevabi (odulKodu dolu) + {yeniUye, oturum: ?{token, user_type, musteri}}
     */
    public function kodDogrula(int $salonId, string $taslakId, string $kod): array
    {
        $taslak = $this->taslak($salonId, $taslakId);
        if (empty($taslak['telefon'])) {
            throw new GecersizIslemException('Önce telefon numaranıza kod isteyin.');
        }
        if (! OnayKodu::dogrulaSinirli(self::kodAmaci($taslak['telefon']), $salonId, 0, $kod, ['taslak' => $taslakId])) {
            throw new GecersizIslemException('Kod hatalı veya süresi dolmuş.');
        }
        // Taslak tek kullanimlik: es zamanli ikinci istek burada durur (Cache::add atomik).
        $kilit = self::kilitAnahtari($taslakId);
        if (! Cache::add($kilit, 1, self::TASLAK_SN)) {
            throw new GecersizIslemException('Bu hediye zaten alındı.');
        }
        try {
            $sonuc = DB::transaction(function () use ($taslak, $salonId) {
                $musteri = $this->oturum->dogrulanmisMusteri($taslak['ad_soyad'], $taslak['telefon'], $salonId);

                return $musteri + ['odul' => $this->cark->dilimUygula($musteri['user_id'], $salonId, (int) $taslak['cark_id'], (int) $taslak['dilim_id'])];
            });
        } catch (\Throwable $e) {
            Cache::forget($kilit); // hicbir sey yazilmadi; musteri baska numarayla yeniden kod isteyebilir
            throw $e;
        }
        Cache::forget(self::taslakAnahtari($taslakId));
        if ($sonuc['yeni']) {
            $this->isletmeyeBildir($salonId, $taslak, $sonuc['odul']['dilim']['baslik']);
        }

        return $sonuc['odul'] + [
            'yeniUye' => $sonuc['yeni'],
            // Telefon kodla dogrulandi: kayitli musteri de giris yapar (kullanici 2026-11-10, sitede kodla giris).
            'oturum'  => $this->oturum->oturumAc($sonuc['user_id'], $salonId),
        ];
    }

    private function taslak(int $salonId, string $id): array
    {
        $t = Cache::get(self::taslakAnahtari($id));
        if (! is_array($t) || (int) ($t['salon_id'] ?? 0) !== $salonId || Cache::has(self::kilitAnahtari($id))) {
            throw new GecersizIslemException('Oturum süresi doldu. Baştan başlayın.');
        }

        return $t;
    }

    /** Eski smsKodDogrula ile ayni: carktan gelen YENI musteri isletmenin bildirimlerine duser. */
    private function isletmeyeBildir(int $salonId, array $taslak, string $odul): void
    {
        $tel = str_starts_with($taslak['telefon'], '+') ? $taslak['telefon'] : '0'.$taslak['telefon'];
        try {
            DB::table('bildirimler')->insert([
                'aciklama' => 'Çarkıfelek üzerinden yeni müşteri kaydı: '.$taslak['ad_soyad'].' ('.$tel.'). Hediye: '.$odul,
                'salon_id' => $salonId, 'url' => '/v2/carkifelek?p=kazananlar',
                'tarih_saat' => now()->format('Y-m-d H:i:s'), 'okundu' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Cark yeni musteri bildirimi yazilamadi: '.$e->getMessage(), ['salon_id' => $salonId]); // odul yazildi, cevap bozulmasin
        }
    }

    /** Kod numaraya baglidir: anahtar (amac:telefon, salon) — gonderim beklemesi ve gunluk sinirlar numara basina. */
    private static function kodAmaci(string $tel): string
    {
        return self::KOD_AMAC.':'.$tel;
    }

    /** Onbellek anahtarinda taslak anahtarinin kendisi tutulmaz. */
    private static function taslakAnahtari(string $id): string
    {
        return 'cark-taslak:'.hash('sha256', $id);
    }

    /** Kullanilmis taslak isareti (taslak omru boyunca); taslak silinse de yeniden yazilsa da ikinci kullanim olmaz. */
    private static function kilitAnahtari(string $id): string
    {
        return self::taslakAnahtari($id).':kullanildi';
    }
}
