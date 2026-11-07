<?php

namespace App\Services\Oturum;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Services\Oturum\Hesap\HesapListesi;
use App\Services\Oturum\Hesap\OturumHesabi;
use App\Support\OnayKodu;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tek kullanimlik kodla giris (web + Flutter tek uc). Ilk gonderim WhatsApp, kod yeniden istenirse SMS.
 * Kayitli olmayan numara ayni cevabi alir, mesaj gitmez. Numara birden cok hesap turune bagliysa
 * dogrulama secim listesi ve yeni bir kod dondurur; istemci turu secip ayni ucu o kodla cagirir.
 * Inceleme hesaplarina mesaj gitmez; magaza incelemesi icin config'deki sabit kodla girerler.
 */
class TekKullanimlikGirisServisi
{
    private const AMAC = 'oturum_giris';

    private const SALON_YOK = 0;

    public function __construct(
        private HesapListesi $hesaplar,
        private OturumServisi $oturum,
        private PlatformMesaj $mesaj,
    ) {}

    public function kodIste(string $telefonHam, ?string $tur, string $ip, ?string $bundle = null): void
    {
        $tel = $this->telefon($telefonHam);
        $ayar = (array) config('oturum.tek_kullanimlik');
        if (! RateLimiter::attempt('oturum-kod-ip:'.$ip, (int) $ayar['ip_saatlik'], fn () => true, 3600)) {
            throw new CokFazlaIstekException();
        }

        $hesaplar = array_filter($this->hesaplariBul($tel, $tur), fn ($h) => ! $this->korunuyor($h[0], $h[1]));
        if (! $hesaplar
            || ! RateLimiter::attempt('oturum-kod-tel:'.$tel, 1, fn () => true, (int) $ayar['yeniden_sn'])
            || ! RateLimiter::attempt('oturum-kod-tel-saat:'.$tel, (int) $ayar['tel_saatlik'], fn () => true, 3600)) {
            return;
        }

        $kod = $this->kodUret($tel);
        [$hesap, $id] = reset($hesaplar);
        $this->mesaj->kod($tel, 'Giriş kodunuz: '.$kod.'. Kod '.intdiv((int) $ayar['sure_sn'], 60).' dakika geçerlidir. Kimseyle paylaşmayın.', 'Giris Kodu', $hesap->mesajSalonu($id, $bundle));
    }

    /** @param array{cep_telefon:string,kod:string,hesap_turu?:?string,app_bundle?:?string,cihaz_bilgi?:?string,bildirim_id?:?string} $g */
    public function dogrula(array $g): array
    {
        $tel = $this->telefon($g['cep_telefon']);
        $hesaplar = $this->hesaplariBul($tel, $g['hesap_turu'] ?? null);
        if (! $hesaplar || ! $this->kodDogruMu($tel, $g['kod'], $hesaplar)) {
            throw new GecersizIslemException('Kod hatalı veya süresi dolmuş.');
        }

        if (count($hesaplar) > 1) {
            return ['hesap_secimi' => array_map(fn ($h) => $h[0]->tur(), $hesaplar), 'secim_kodu' => $this->kodUret($tel)];
        }

        return $this->oturum->oturumAc($hesaplar[0][0], $hesaplar[0][1], $g);
    }

    /** @return array<int,array{0:OturumHesabi,1:int}> */
    private function hesaplariBul(string $tel, ?string $tur): array
    {
        $sonuc = [];
        foreach ($this->hesaplar->secili($tur) as $hesap) {
            $id = $hesap->telefonlaBul($tel);
            if ($id !== null) {
                $sonuc[] = [$hesap, $id];
            }
        }

        return $sonuc;
    }

    private function kodDogruMu(string $tel, string $kod, array $hesaplar): bool
    {
        $inceleme = (string) config('oturum.tek_kullanimlik.inceleme_kodu');
        foreach ($hesaplar as [$hesap, $id]) {
            if ($this->korunuyor($hesap, $id)) {
                return $inceleme !== '' && hash_equals($inceleme, trim($kod));
            }
        }

        return OnayKodu::dogrula(self::AMAC, self::SALON_YOK, $this->sahip($tel), $kod);
    }

    private function kodUret(string $tel): string
    {
        $ayar = (array) config('oturum.tek_kullanimlik');

        return OnayKodu::uret(self::AMAC, self::SALON_YOK, $this->sahip($tel), [], (int) $ayar['hane'], (int) $ayar['sure_sn']);
    }

    private function korunuyor(OturumHesabi $hesap, int $id): bool
    {
        return in_array($id, (array) config('oturum.korunan_hesaplar.'.$hesap->tur()), true);
    }

    private function telefon(string $ham): string
    {
        $tel = TelefonNumarasi::kanonik($ham);
        if (! TelefonNumarasi::gecerliMi($tel)) {
            throw new GecersizIslemException('Geçerli bir telefon numarası girin.');
        }

        return $tel;
    }

    /** Kod hesap turunden once uretilir: sahibi numaranin kendisidir. */
    private function sahip(string $tel): int
    {
        return (int) preg_replace('/\D/', '', $tel);
    }
}
