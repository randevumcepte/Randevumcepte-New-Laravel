<?php

namespace App\Console\Commands;

use App\Domain\Karsilastirma\KontrolKosucusu;
use App\Domain\Karsilastirma\MobilTutarKontrolleri;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Eski mobil ucu ile yeni ucu ayni girdilerle cagirir, listelenen alanlari karsilastirir. SALT OKUMA: veritabanina ve eski sisteme
 * yazmaz; kontrol listesi MobilTutarKontrolleri'ndedir. Fark veya istek hatasi varsa cikis kodu 1.
 *
 * Token ortamdan okunabilir, boylece kabuk gecmisine girmez; ekrana ve loga yazilmaz:
 *   MOBIL_KARSILASTIR_TOKEN=... php artisan mobil:tutar-karsilastir 20 --bas=2026-10-01 --bit=2026-10-31 \
 *     --sunucu=https://api.randevumcepte.com.tr --deger=musteri_id=5 --deger=personel_id=7 --deger=user_id=3
 *
 * Token imzali mobil token olmali; degilse sunucu `mobil` bayragini siler ve web cevabi doner.
 * Atlanan kontrol esitlik kaniti degildir: ozet satirinda sayilir, nedeni yazilir.
 */
class MobilTutarKarsilastir extends Command
{
    protected $signature = 'mobil:tutar-karsilastir
        {salon_id : karsilastirilacak salon}
        {--bas= : Y-m-d, varsayilan ayin ilk gunu}
        {--bit= : Y-m-d, varsayilan bugun}
        {--token= : isletme token, yoksa MOBIL_KARSILASTIR_TOKEN}
        {--musteri-token= : musteri uygulamasi token, yoksa MOBIL_KARSILASTIR_MUSTERI_TOKEN}
        {--personel-token= : rol 5 token, yoksa MOBIL_KARSILASTIR_PERSONEL_TOKEN}
        {--sunucu= : yeni uc sunucusu, varsayilan config app.url}
        {--deger=* : ad=deger; musteri_id, personel_id, user_id, personel_user_id, adisyon_id, adisyon_paket_id, adisyon_hizmet_id, hizmet_id, urun_id, paket_id, faturasiz_salon, studyo_salon, arama, harcayan, odeme_yontemi_id}
        {--kontrol= : yalniz adi bu metni iceren kontroller}
        {--bekle=200 : istekler arasi milisaniye}';

    protected $description = 'Eski mobil uc ile yeni ucun tutar ve sayac esitligi (salt okuma)';

    private const ZAMAN_ASIMI_SN = 60;

    private const COK_ISTEK_BEKLEME_UST_SN = 60;

    public function handle(): int
    {
        $tokenlar = array_filter([
            MobilTutarKontrolleri::TOKEN_ISLETME => $this->option('token') ?: getenv('MOBIL_KARSILASTIR_TOKEN'),
            MobilTutarKontrolleri::TOKEN_MUSTERI => $this->option('musteri-token') ?: getenv('MOBIL_KARSILASTIR_MUSTERI_TOKEN'),
            MobilTutarKontrolleri::TOKEN_PERSONEL => $this->option('personel-token') ?: getenv('MOBIL_KARSILASTIR_PERSONEL_TOKEN'),
        ]);
        if (! isset($tokenlar[MobilTutarKontrolleri::TOKEN_ISLETME])) {
            $this->error('Token gerekli: --token veya MOBIL_KARSILASTIR_TOKEN.');

            return self::INVALID;
        }

        try {
            $bas = Carbon::createFromFormat('!Y-m-d', $this->option('bas') ?: now()->startOfMonth()->format('Y-m-d'));
            $bit = Carbon::createFromFormat('!Y-m-d', $this->option('bit') ?: now()->format('Y-m-d'));
        } catch (Throwable) {
            $this->error('--bas ve --bit Y-m-d olmali.');

            return self::INVALID;
        }

        $sunucu = rtrim((string) ($this->option('sunucu') ?: config('app.url')), '/');
        $kosucu = new KontrolKosucusu(
            fn (string $yontem, string $adres, array $sorgu, array $govde, ?string $token) => $this->istek($yontem, $adres, $sorgu, $govde, $token),
            $this->degerler($bas, $bit),
            MobilTutarKontrolleri::ESKI_TABAN,
            $sunucu.'/api/v2',
            $tokenlar,
        );

        $sayac = array_fill_keys([KontrolKosucusu::ESIT, KontrolKosucusu::FARK, KontrolKosucusu::ATLANDI, KontrolKosucusu::ELLE], 0);
        foreach (MobilTutarKontrolleri::hepsi() as $kontrol) {
            if (($suzgec = $this->option('kontrol')) && stripos($kontrol['ad'], $suzgec) === false) {
                continue;
            }
            $sonuc = $kosucu->calistir($kontrol);
            $sayac[$sonuc['durum']]++;
            $this->yazdir($sonuc);
        }

        $this->newLine();
        $this->line("Esit {$sayac['esit']}, fark {$sayac['fark']}, atlanan {$sayac['atlandi']}, elle bakilacak {$sayac['elle']}.");
        foreach (MobilTutarKontrolleri::KAPSAM_DISI as $ad => $neden) {
            $this->line("Kapsam disi: $ad ($neden)");
        }
        foreach (MobilTutarKontrolleri::BILINCLI_FARK as $ad => $neden) {
            $this->line("Bilincli fark: $ad ($neden)");
        }

        return $sayac[KontrolKosucusu::FARK] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string,string> */
    private function degerler(Carbon $bas, Carbon $bit): array
    {
        $degerler = [];
        foreach ((array) $this->option('deger') as $satir) {
            [$ad, $deger] = array_pad(explode('=', (string) $satir, 2), 2, '');
            $degerler[$ad] = $deger;
        }

        return [
            'salon' => (string) $this->argument('salon_id'),
            'bas' => $bas->format('Y-m-d'),
            'bit' => $bit->format('Y-m-d'),
            'bugun' => now()->format('Y-m-d'),
            'ay' => $bas->format('m'),
            'yil' => $bas->format('Y'),
            'donem' => $bas->format('Y-m'),
            'app_bundle' => (string) config('oturum.ana_uygulama_bundle'),
        ] + $degerler;
    }

    /** @return array{0:int,1:string} */
    private function istek(string $yontem, string $adres, array $sorgu, array $govde, ?string $token): array
    {
        usleep(max(0, (int) $this->option('bekle')) * 1000);
        $secenek = ['query' => $sorgu] + ($yontem === 'GET' ? [] : ['json' => $govde]);
        $gonder = fn () => Http::withToken((string) $token)->acceptJson()->timeout(self::ZAMAN_ASIMI_SN)->send($yontem, $adres, $secenek);

        $cevap = $gonder();
        if ($cevap->status() === 429) {
            sleep(min(self::COK_ISTEK_BEKLEME_UST_SN, max(1, (int) $cevap->header('Retry-After'))));
            $cevap = $gonder();
        }

        return [$cevap->status(), $cevap->body()];
    }

    /** @param  array{ad:string,durum:string,farklar:array<int,string>,neden:?string}  $sonuc */
    private function yazdir(array $sonuc): void
    {
        $this->line(strtoupper($sonuc['durum']).'  '.$sonuc['ad'].($sonuc['neden'] ? ' ('.$sonuc['neden'].')' : ''));
        foreach ($sonuc['farklar'] as $fark) {
            $this->line('    '.$fark);
        }
    }
}
