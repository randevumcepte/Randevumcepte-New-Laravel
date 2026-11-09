<?php

namespace App\Services\Online;

use App\Exceptions\CokFazlaIstekException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Musterinin online randevu olusturma sinirlari (mini site + musteri uygulamasi TEK yol) — eski
 * HomeController@randevuonaylaDirekt ile ayni degerler: musteri (telefon) basina salon basina gunde 3; web
 * formunda bal kupu ve 3 sn kurali (BotKapisi). Eskiden v2 ucunda hicbiri yoktu: bir musteri token'iyla
 * sinirsiz randevu talebi acilabiliyordu.
 *
 * IP siniri (saatte 5) YALNIZ kimliksiz / misafir yolda ($misafirIp dolu) uygulanir. Kimligi dogrulanmis musteri
 * isteginde uygulanmaz: ayni IP'yi paylasan musteriler (isyeri / mobil operator NAT'i, ters vekil arkasinda tek IP)
 * birbirini kilitlemesin; orada musteri basina sinir yeterlidir.
 *
 * Yalniz BASARILI olusturma sayilir (cakisan saat, kapali salon hakki yemez); idempotent tekrar sayilmaz.
 */
class OnlineRandevuSiniri
{
    /** @param  ?string  $misafirIp  yalniz kimliksiz / misafir yolda; kimlikli musteri isteginde null */
    public function denetle(int $salonId, int $musteriId, ?string $misafirIp, array $g): void
    {
        if (($g['kaynak'] ?? null) === 'web') {
            BotKapisi::denetle($g);
        }
        if ($misafirIp !== null && RateLimiter::tooManyAttempts(self::ipAnahtari($misafirIp), (int) config('online.randevu_ip_saatlik'))) {
            throw new CokFazlaIstekException('Çok fazla randevu denemesi yapıldı. Lütfen 1 saat sonra tekrar deneyin.');
        }
        if (RateLimiter::tooManyAttempts(self::musteriAnahtari($salonId, $musteriId), (int) config('online.randevu_musteri_gunluk'))) {
            throw new CokFazlaIstekException('Bu numara için bugün bu işletmede çok fazla randevu talebi yapıldı. Yarın tekrar deneyin.');
        }
    }

    public function say(int $salonId, int $musteriId, ?string $misafirIp): void
    {
        if ($misafirIp !== null) {
            RateLimiter::hit(self::ipAnahtari($misafirIp), 3600);
        }
        RateLimiter::hit(self::musteriAnahtari($salonId, $musteriId), 86400);
    }

    public static function ipAnahtari(string $ip): string
    {
        return 'online-randevu-ip:'.sha1($ip);
    }

    public static function musteriAnahtari(int $salonId, int $musteriId): string
    {
        return 'online-randevu-musteri:'.$salonId.':'.$musteriId;
    }
}
