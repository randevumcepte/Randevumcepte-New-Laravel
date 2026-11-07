<?php

namespace App\Services\MusteriHesap;

use App\Domain\Oturum\TokenTipi;
use App\Exceptions\GirisHatasiException;
use App\Services\Oturum\ErisimTokenServisi;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Throwable;

/**
 * Suresi dolmus musteri token'ini yeniler. Eski uygulamada musteri uclari token denetlemedigi icin bir yil
 * acmayan musteri oturumunu kaybetmiyordu; v2'de ayni musteri girise dusmesin diye vardir.
 *
 * Yalniz sureyi affeder, kimligi gevsetmez: imza dogrulanir, satir oauth_access_tokens'ta bulunmali ve iptal
 * edilmemis olmali (cikis ve sifre degisimi token'i iptal eder), yalniz imzali TokenTipi::MUSTERI token'i kabul
 * edilir: tipsiz eski token, kopru, isletme ve sistem yoneticisi token'i reddedilir (users ve isletmeyetkilileri
 * id'leri cakisir, tipsiz token'dan musteri kimligi cikarilamaz). Bitis ustunden KABUL_GUN gectiyse yeni giris
 * gerekir. Yeni token eskisinin mobil kapsamini korur, cikinca eskisi iptal edilir.
 * Suresi dolmamis token icin yeni token uretmez (o yol POST /oturum/yenile).
 */
class MusteriTokenYenilemeServisi
{
    public const KABUL_GUN = 730;

    public function __construct(private ErisimTokenServisi $token) {}

    /** @return array{token:?string,expires_at:?string} */
    public function yenile(?string $jwt): array
    {
        $kimlik = $this->coz((string) $jwt);
        $satir = DB::table('oauth_access_tokens')->where('id', $kimlik['id'])->first(['user_id', 'revoked', 'expires_at']);
        if (! $satir || (int) $satir->revoked === 1 || (int) $satir->user_id !== $kimlik['user_id']) {
            throw $this->red();
        }
        $bitis = $satir->expires_at ? strtotime((string) $satir->expires_at) : null;
        if ($bitis !== null && $bitis > time()) {
            return ['token' => null, 'expires_at' => (string) $satir->expires_at];
        }
        if ($bitis !== null && $bitis < time() - self::KABUL_GUN * 86400) {
            throw $this->red();
        }
        if (! DB::table('users')->where('id', $kimlik['user_id'])->exists()) {
            throw $this->red();
        }

        $yeni = $this->token->uret($kimlik['user_id'], TokenTipi::MUSTERI, $kimlik['mobil']);
        $this->token->iptal($kimlik['id']);

        return ['token' => $yeni, 'expires_at' => $this->token->bitisTarihi()];
    }

    /** @return array{id:string,user_id:int,mobil:bool} */
    private function coz(string $jwt): array
    {
        try {
            $ayar = Configuration::forAsymmetricSigner(new Sha256(), InMemory::file(storage_path('oauth-private.key')), InMemory::file(storage_path('oauth-public.key')));
            $token = $ayar->parser()->parse($jwt);
            if (! $token instanceof UnencryptedToken || ! $ayar->validator()->validate($token, new SignedWith($ayar->signer(), $ayar->verificationKey()))) {
                throw $this->red();
            }
            $sub = (string) $token->claims()->get('sub');
            $id = (string) $token->claims()->get('jti');
            $kapsam = (array) $token->claims()->get('scopes', []);
        } catch (GirisHatasiException $e) {
            throw $e;
        } catch (Throwable) {
            throw $this->red();
        }
        if (! ctype_digit($sub) || $id === '' || TokenTipi::coz($kapsam) !== TokenTipi::MUSTERI) {
            throw $this->red();
        }

        return ['id' => $id, 'user_id' => (int) $sub, 'mobil' => TokenTipi::mobilMi($kapsam)];
    }

    private function red(): GirisHatasiException
    {
        return new GirisHatasiException('Oturum yenilenemedi.');
    }
}
