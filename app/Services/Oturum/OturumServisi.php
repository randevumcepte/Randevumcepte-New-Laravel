<?php

namespace App\Services\Oturum;

use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GirisHatasiException;
use App\Services\Oturum\Hesap\HesapListesi;
use App\Services\Oturum\Hesap\OturumHesabi;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tek giris ucu: web paneli (hesap_turu=isletme) ve Flutter (bundle ile, her iki hesap turu).
 * Yanlis denemeler kimlik+IP bazinda sayilir.
 */
class OturumServisi
{
    public function __construct(private HesapListesi $hesaplar, private ErisimTokenServisi $token) {}

    /** @param array{kimlik:string,password:string,hesap_turu?:?string,app_bundle?:?string,bildirim_id?:?string,cihaz_bilgi?:?string} $g */
    public function giris(array $g, string $ip): array
    {
        $kimlik = trim($g['kimlik']);
        $anahtar = 'oturum-giris:'.sha1(mb_strtolower($kimlik)).'|'.$ip;
        if (RateLimiter::tooManyAttempts($anahtar, (int) config('oturum.giris_deneme'))) {
            throw new CokFazlaIstekException();
        }
        $bundle = ($g['app_bundle'] ?? null) ?: null;

        foreach ($this->hesaplar->secili($g['hesap_turu'] ?? null) as $hesap) {
            $id = $hesap->sifreyleBul($kimlik, $g['password']);
            if ($id === null) {
                continue;
            }
            RateLimiter::clear($anahtar);

            return $this->oturumAc($hesap, $id, $g);
        }

        RateLimiter::hit($anahtar, (int) config('oturum.giris_deneme_sn'));
        throw new GirisHatasiException('Yanlış kullanıcı adı veya şifre girdiniz.');
    }

    /** Dogrulanmis hesap icin oturum cevabi (sifre ve tek kullanimlik kod ortak). */
    public function oturumAc(OturumHesabi $hesap, int $id, array $g): array
    {
        $bundle = ($g['app_bundle'] ?? null) ?: null;
        $veri = $hesap->acilisVerisi($id, $bundle);
        $hesap->cihazKaydet($id, $g['bildirim_id'] ?? null, $g['cihaz_bilgi'] ?? null, $bundle);

        return [
            'token'      => $this->token->uret($id, $hesap->tur()),
            'token_type' => 'Bearer',
            'expires_at' => $this->token->bitisTarihi(),
            'user_type'  => $hesap->kullaniciTipi(),
        ] + $veri;
    }

    /** @return array{token:?string,expires_at:?string} token null: yenilemeye henuz gerek yok */
    public function yenile(?string $tokenId, int $userId, ?string $tip): array
    {
        if (! $tokenId || ! $tip) {
            throw new GirisHatasiException('Oturumunuzu yeniden açmanız gerekiyor.');
        }
        $token = $this->token->yenile($tokenId, $userId, $tip);

        return ['token' => $token, 'expires_at' => $token ? $this->token->bitisTarihi() : null];
    }

    public function cikis(?string $tokenId): void
    {
        if ($tokenId) {
            $this->token->iptal($tokenId);
        }
    }
}
