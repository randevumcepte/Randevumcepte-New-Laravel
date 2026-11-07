<?php

namespace App\Services\Oturum;

use App\Domain\Oturum\TokenTipi;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/**
 * Erisim token'i. Eski Passport'un personal access token'i ile AYNI bicim: RS256 JWT + oauth_access_tokens
 * satiri, ortak anahtar. Boylece musteri/isletme token'i hem v2 guard'larinda hem v1 uclarinda gecerlidir.
 * Tarih claim'leri tam sayi: eski Passport 4 kutuphanesi kesirli degerleri okuyamaz.
 *
 * Uc tur:
 *   uret()                 musteri / isletme yetkilisi (subject = sayisal id)
 *   sistemYoneticisiUret() sistem yonetimi ekibi (subject = "sy:<id>"; sayisal id bekleyen guard'lar reddeder)
 *   impersonationUret()    "Salon hesabina gir": yetkilinin kisa omurlu token'i (token id "imp_" onekli)
 */
class ErisimTokenServisi
{
    private const TOKEN_ADI = 'appToken';

    private const SISTEM_TOKEN_ADI = 'sistemYonetim';

    private const SISTEM_SUBJECT_ONEKI = 'sy:';

    private const IMPERSONATION_ID_ONEKI = 'imp_';

    private const IMPERSONATION_ADI_ONEKI = 'impersonation:';

    /** @param  string  $tip  TokenTipi: token yalniz bu hesap turunun uclarinda gecer */
    public function uret(int $userId, string $tip): string
    {
        return $this->imzala($userId, (string) $userId, self::TOKEN_ADI, (new DateTimeImmutable())->modify('+'.(int) config('online.token_gun').' days'), '', [$tip]);
    }

    /** Bitimine az kalan token icin yeni token; erken cagrida null. Eski token suresince gecerli kalir (cevap kaybolursa oturum dusmesin). */
    public function yenile(string $tokenId, int $userId, string $tip): ?string
    {
        $bitis = DB::table('oauth_access_tokens')->where('id', $tokenId)->value('expires_at');
        if ($bitis !== null && strtotime((string) $bitis) > time() + (int) config('oturum.token_yenile_gun') * 86400) {
            return null;
        }

        return $this->uret($userId, $tip);
    }

    public function sistemYoneticisiUret(int $id): string
    {
        return $this->imzala($id, self::SISTEM_SUBJECT_ONEKI.$id, self::SISTEM_TOKEN_ADI, new DateTimeImmutable($this->sistemBitisTarihi()));
    }

    /** Token satiri $logId ile bulunur: oturum bitince yalniz bu token iptal edilir. */
    public function impersonationUret(int $yetkiliId, int $logId): string
    {
        $bitis = (new DateTimeImmutable())->modify('+'.(int) config('sistem.impersonation_saat').' hours');

        return $this->imzala($yetkiliId, (string) $yetkiliId, self::IMPERSONATION_ADI_ONEKI.$logId, $bitis, self::IMPERSONATION_ID_ONEKI, [TokenTipi::ISLETME]);
    }

    /** Bugun uretilecek token'in bitis zamani (istemciye bilgi amacli). */
    public function bitisTarihi(): string
    {
        return now()->addDays((int) config('online.token_gun'))->toDateTimeString();
    }

    public function sistemBitisTarihi(): string
    {
        return now()->addHours((int) config('sistem.token_saat'))->toDateTimeString();
    }

    public function iptal(string $tokenId): void
    {
        DB::table('oauth_access_tokens')->where('id', $tokenId)->update(['revoked' => 1, 'updated_at' => now()]);
    }

    /**
     * Sifre degisince diger oturumlar duser; $haric (islemi yapan oturum) korunur. user_id tablolar arasi
     * cakistigi icin sistem yoneticisi token'lari kapsam disi: yetkili #3 sifresi yonetici #3'u dusurmez.
     */
    public function digerleriniIptal(int $userId, ?string $haric): void
    {
        DB::table('oauth_access_tokens')->where('user_id', $userId)->where('revoked', 0)
            ->where(fn ($q) => $q->whereNull('name')->orWhere('name', '!=', self::SISTEM_TOKEN_ADI))
            ->when($haric, fn ($q) => $q->where('id', '!=', $haric))
            ->update(['revoked' => 1, 'updated_at' => now()]);
    }

    public function sistemDigerleriniIptal(int $yoneticiId, ?string $haric): void
    {
        DB::table('oauth_access_tokens')->where('user_id', $yoneticiId)->where('revoked', 0)->where('name', self::SISTEM_TOKEN_ADI)
            ->when($haric, fn ($q) => $q->where('id', '!=', $haric))
            ->update(['revoked' => 1, 'updated_at' => now()]);
    }

    public function impersonationIptal(int $logId): void
    {
        DB::table('oauth_access_tokens')->where('name', self::IMPERSONATION_ADI_ONEKI.$logId)->update(['revoked' => 1, 'updated_at' => now()]);
    }

    /** Subject sistem yoneticisine aitse id'si, degilse null. */
    public static function sistemSubjecttenId(string $sub): ?int
    {
        return preg_match('/^'.preg_quote(self::SISTEM_SUBJECT_ONEKI, '/').'(\d+)$/', $sub, $m) ? (int) $m[1] : null;
    }

    public static function impersonationTokeniMi(?string $tokenId): bool
    {
        return $tokenId !== null && str_starts_with($tokenId, self::IMPERSONATION_ID_ONEKI);
    }

    private function imzala(int $satirKullanici, string $subject, string $ad, DateTimeImmutable $bitis, string $idOneki = '', array $kapsam = []): string
    {
        $simdi = new DateTimeImmutable();
        $id = $idOneki.bin2hex(random_bytes(40));
        $clientId = (int) config('online.token_client_id');

        DB::table('oauth_access_tokens')->insert([
            'id' => $id, 'user_id' => $satirKullanici, 'client_id' => $clientId, 'name' => $ad, 'scopes' => '[]', 'revoked' => 0,
            'created_at' => $simdi->format('Y-m-d H:i:s'), 'updated_at' => $simdi->format('Y-m-d H:i:s'), 'expires_at' => $bitis->format('Y-m-d H:i:s'),
        ]);

        $yapilandirma = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::file(storage_path('oauth-private.key')),
            InMemory::file(storage_path('oauth-public.key')),
        );

        return $yapilandirma->builder(ChainedFormatter::withUnixTimestampDates())
            ->permittedFor((string) $clientId)
            ->identifiedBy($id)
            ->issuedAt($simdi)
            ->canOnlyBeUsedAfter($simdi)
            ->expiresAt($bitis)
            ->relatedTo($subject)
            ->withClaim('scopes', $kapsam)
            ->getToken($yapilandirma->signer(), $yapilandirma->signingKey())
            ->toString();
    }
}
