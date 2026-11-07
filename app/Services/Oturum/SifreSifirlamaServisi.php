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
 * Iki adimli sifre sifirlama: SMS kodu + yeni sifre. Eski kodun aciklari kapatildi:
 *   - web: yetkili YALNIZ 4 haneli kodla aranip sifresi degisiyordu (baska hesaba denk gelebilir)
 *   - v1: numarayi bilen herkes sifreyi aninda degistirebiliyordu
 *   - kayitli olmayan numara ayni cevabi alir (hesap varligi sizmaz)
 * Inceleme hesaplari (config oturum.korunan_hesaplar) sifirlanmaz, SMS gitmez.
 */
class SifreSifirlamaServisi
{
    private const SALON_YOK = 0;

    public function __construct(private HesapListesi $hesaplar, private PlatformMesaj $sms) {}

    public function kodGonder(string $telefonHam, ?string $tur, string $ip): void
    {
        $tel = TelefonNumarasi::kanonik($telefonHam);
        if (! TelefonNumarasi::gecerliMi($tel)) {
            throw new GecersizIslemException('Geçerli bir telefon numarası girin.');
        }
        if (! RateLimiter::attempt('oturum-sifre-ip:'.$ip, (int) config('oturum.sifre_kodu_ip_gunluk'), fn () => true, 86400)) {
            throw new CokFazlaIstekException();
        }
        if (! RateLimiter::attempt('oturum-sifre-tel:'.$tel, 1, fn () => true, (int) config('oturum.sifre_kodu_tel_sn'))) {
            return;
        }
        [$hesap, $id] = $this->hesapBul($tel, $tur);
        if (! $hesap || $this->korunuyor($hesap, $id)) {
            return;
        }
        $kod = OnayKodu::uret($this->amac($hesap), self::SALON_YOK, $id);
        $this->sms->kod($tel, 'Randevumcepte şifre yenileme kodunuz: '.$kod.'. Kod 10 dakika geçerlidir.', 'Sifre Yenileme');
    }

    public function sifirla(string $telefonHam, string $kod, string $yeniSifre, ?string $tur): void
    {
        [$hesap, $id] = $this->hesapBul(TelefonNumarasi::kanonik($telefonHam), $tur);
        if (! $hesap || $this->korunuyor($hesap, $id) || ! OnayKodu::dogrula($this->amac($hesap), self::SALON_YOK, $id, $kod)) {
            throw new GecersizIslemException('Kod hatalı veya süresi dolmuş.');
        }
        $hesap->sifreGuncelle($id, $yeniSifre);
    }

    /** @return array{0:?OturumHesabi,1:?int} */
    private function hesapBul(string $tel, ?string $tur): array
    {
        foreach ($this->hesaplar->secili($tur) as $hesap) {
            $id = $hesap->telefonlaBul($tel);
            if ($id !== null) {
                return [$hesap, $id];
            }
        }

        return [null, null];
    }

    private function korunuyor(OturumHesabi $hesap, int $id): bool
    {
        return in_array($id, (array) config('oturum.korunan_hesaplar.'.$hesap->tur()), true);
    }

    private function amac(OturumHesabi $hesap): string
    {
        return 'oturum_sifre_'.$hesap->tur();
    }
}
