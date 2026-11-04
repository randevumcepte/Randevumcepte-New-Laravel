<?php

namespace App\Services\Deneme;

use App\Domain\Telefon\TelefonNumarasi;
use App\Exceptions\CakismaException;
use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GecersizIslemException;
use App\Services\Oturum\Hesap\YetkiliHesabi;
use App\Services\Oturum\PlatformSms;
use App\Support\OnayKodu;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Ucretsiz deneme kaydi, iki adim: form + SMS kodu, sonra kod dogrulaninca hesap acilir.
 * Eskiden farkli olarak kodun yalniz hash'i saklanir ve SMS gonderimi IP + numara bazinda sinirlidir
 * (kimliksiz uctan ucretli SMS pompalanmasin).
 */
class DenemeKayitServisi
{
    private const KOD_AMAC = 'deneme_kayit';

    private const SALON_YOK = 0;

    public function __construct(
        private YetkiliHesabi $yetkili,
        private DemoHesapKurServisi $kurulum,
        private PlatformSms $sms,
    ) {}

    /** @param array<string,mixed> $form doğrulanmış alanlar */
    public function kodGonder(array $form, string $ip): int
    {
        $tel = $this->telefon($form['cep_telefon']);
        $this->uyeDegil($tel);
        $this->sinirla('deneme-kod-ip:'.$ip, (int) config('oturum.deneme.kod_ip_saatlik'), 3600);
        $this->sinirla('deneme-kod-tel:'.$tel, 1, (int) config('oturum.deneme.kod_tel_sn'));

        $sure = (int) config('oturum.deneme.kod_sure_sn');
        Cache::put($this->bekleyenAnahtari($tel), ['cep_telefon' => $tel] + $form, $sure);
        $kod = OnayKodu::uret(self::KOD_AMAC, self::SALON_YOK, $this->kodSahibi($tel));
        if (! $this->sms->gonder($tel, 'Randevumcepte üyelik doğrulama kodunuz: '.$kod.'. Kod 10 dakika geçerlidir.', 'Uyelik Dogrulama')) {
            Cache::forget($this->bekleyenAnahtari($tel));
            throw new GecersizIslemException("Doğrulama SMS'i gönderilemedi. Lütfen numaranızı kontrol edip tekrar deneyiniz.");
        }

        return $sure;
    }

    /** @return array{salon_id:int,sifre_gonderildi:bool} */
    public function dogrula(string $telefonHam, string $kod): array
    {
        $tel = $this->telefon($telefonHam);
        $form = Cache::get($this->bekleyenAnahtari($tel));
        if (! $form) {
            throw new GecersizIslemException('Doğrulama süresi doldu. Lütfen kaydı yeniden başlatınız.');
        }
        if (! OnayKodu::dogrula(self::KOD_AMAC, self::SALON_YOK, $this->kodSahibi($tel), $kod)) {
            throw new GecersizIslemException('Doğrulama kodu hatalı veya süresi dolmuş.');
        }
        Cache::forget($this->bekleyenAnahtari($tel));
        $this->uyeDegil($tel);

        $sonuc = $this->kurulum->kur($form);
        $gitti = $this->sms->gonder($tel, "Randevumcepte'ye hoş geldiniz. Kullanıcı adınız: {$tel} şifreniz: {$sonuc['sifre']} Panel: ".url('/v2/giris'), 'Yeni Hesap');

        return ['salon_id' => $sonuc['salon_id'], 'sifre_gonderildi' => $gitti];
    }

    private function telefon(string $ham): string
    {
        $tel = TelefonNumarasi::kanonik($ham);
        if (! TelefonNumarasi::gecerliMi($tel)) {
            throw new GecersizIslemException('Lütfen geçerli bir cep telefonu numarası giriniz.');
        }

        return $tel;
    }

    private function uyeDegil(string $tel): void
    {
        if ($this->yetkili->telefonlaBul($tel) !== null) {
            throw new CakismaException('Girdiğiniz telefon numarası ile daha önceden açılmış bir üyelik bulunmaktadır. Farklı bir telefon numarası ile tekrar deneyiniz.');
        }
    }

    private function sinirla(string $anahtar, int $limit, int $sn): void
    {
        if (! RateLimiter::attempt($anahtar, $limit, fn () => true, $sn)) {
            throw new CokFazlaIstekException('Çok sık istek yapıldı. Lütfen biraz sonra tekrar deneyiniz.');
        }
    }

    private function bekleyenAnahtari(string $tel): string
    {
        return 'deneme_bekleyen:'.$tel;
    }

    /** Henuz hesap yok: OnayKodu sahibi olarak telefonun rakamlari kullanilir. */
    private function kodSahibi(string $tel): int
    {
        return (int) preg_replace('/\D/', '', $tel);
    }
}
