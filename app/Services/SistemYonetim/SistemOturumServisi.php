<?php

namespace App\Services\SistemYonetim;

use App\Exceptions\CokFazlaIstekException;
use App\Exceptions\GirisHatasiException;
use App\Models\SistemYoneticisi;
use App\Services\Oturum\ErisimTokenServisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sistem yonetimi girisi (eski AuthSuperAdmin\LoginController). Her deneme sistemyonetim_login_loglari'na yazilir.
 * Pasif bilgisi yalniz sifre dogruysa verilir: e-postanin kayitli oldugu disaridan anlasilmaz.
 */
class SistemOturumServisi
{
    public function __construct(private ErisimTokenServisi $token, private ImpersonationServisi $impersonation) {}

    public function giris(string $email, string $sifre, string $ip, string $tarayici): array
    {
        $email = trim($email);
        $anahtar = 'sistem-giris:'.sha1(mb_strtolower($email)).'|'.$ip;
        if (RateLimiter::tooManyAttempts($anahtar, (int) config('sistem.giris_deneme'))) {
            throw new CokFazlaIstekException();
        }

        $yonetici = SistemYoneticisi::where('email', $email)->first();
        $sifreDogru = $yonetici && Hash::check($sifre, (string) $yonetici->password);
        if (! $sifreDogru) {
            RateLimiter::hit($anahtar, (int) config('sistem.giris_deneme_sn'));
            $this->logla($yonetici?->id, $email, false, 'Hatalı kimlik bilgisi', $ip, $tarayici);
            throw new GirisHatasiException('E-posta veya şifre hatalı.');
        }
        if ((int) $yonetici->aktif !== 1) {
            $this->logla($yonetici->id, $email, false, 'Hesap pasif', $ip, $tarayici);
            throw new GirisHatasiException('Hesabınız pasif edilmiş. Yöneticinize başvurun.');
        }

        RateLimiter::clear($anahtar);
        DB::table('sistemyoneticileri')->where('id', $yonetici->id)->update(['son_giris_tarihi' => now(), 'son_giris_ip' => $ip]);
        $this->logla($yonetici->id, $email, true, null, $ip, $tarayici);
        auth('sistemyonetim-api')->setUser($yonetici);
        Denetim::yaz('login', 'sistem_yoneticisi', $yonetici->id, $yonetici->name);

        return [
            'token'      => $this->token->sistemYoneticisiUret($yonetici->id),
            'token_type' => 'Bearer',
            'expires_at' => $this->token->sistemBitisTarihi(),
            'kullanici'  => self::kullaniciOzeti($yonetici),
            'ilk_sayfa'  => '/sistemyonetim/dashboard',
        ];
    }

    /** Oturum token'i ve yoneticinin acik "salon hesabina gir" oturumlari birlikte kapanir. */
    public function cikis(?string $tokenId, ?int $yoneticiId = null): void
    {
        if ($tokenId) {
            $this->token->iptal($tokenId);
        }
        if ($yoneticiId) {
            $this->impersonation->yoneticininOturumlariniKapat($yoneticiId);
        }
    }

    public static function kullaniciOzeti(SistemYoneticisi $y): array
    {
        $rol = $y->rol();

        return ['id' => (int) $y->id, 'ad' => $y->name, 'email' => $y->email, 'telefon' => $y->telefon, 'rol' => $rol->value, 'rol_etiket' => $rol->etiket()];
    }

    private function logla(?int $yoneticiId, string $email, bool $basarili, ?string $hata, string $ip, string $tarayici): void
    {
        DB::table('sistemyonetim_login_loglari')->insert([
            'user_id' => $yoneticiId, 'email_attempt' => mb_substr($email, 0, 191), 'basarili' => $basarili ? 1 : 0, 'hata' => $hata,
            'ip' => $ip, 'user_agent' => mb_substr($tarayici, 0, 255), 'created_at' => now(),
        ]);
    }
}
