<?php

namespace App\Services\Oturum;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/**
 * Erisim token'i (musteri ve isletme yetkilisi). Eski Passport'un personal access token'i ile AYNI bicim: RS256 JWT
 * + oauth_access_tokens satiri, ortak anahtar. Boylece token hem v2 guard'larinda hem v1 uclarinda gecerlidir.
 * Tarih claim'leri tam sayi: eski Passport 4 kutuphanesi kesirli degerleri okuyamaz.
 */
class ErisimTokenServisi
{
    private const TOKEN_ADI = 'appToken';

    public function uret(int $userId): string
    {
        $simdi = new DateTimeImmutable();
        $bitis = $simdi->modify('+'.(int) config('online.token_gun').' days');
        $id = bin2hex(random_bytes(40));
        $clientId = (int) config('online.token_client_id');

        DB::table('oauth_access_tokens')->insert([
            'id' => $id, 'user_id' => $userId, 'client_id' => $clientId, 'name' => self::TOKEN_ADI, 'scopes' => '[]', 'revoked' => 0,
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
            ->relatedTo((string) $userId)
            ->withClaim('scopes', [])
            ->getToken($yapilandirma->signer(), $yapilandirma->signingKey())
            ->toString();
    }

    /** Bugun uretilecek token'in bitis zamani (istemciye bilgi amacli). */
    public function bitisTarihi(): string
    {
        return now()->addDays((int) config('online.token_gun'))->toDateTimeString();
    }

    public function iptal(string $tokenId): void
    {
        DB::table('oauth_access_tokens')->where('id', $tokenId)->update(['revoked' => 1, 'updated_at' => now()]);
    }

    /** Sifre degisince diger oturumlar duser; $haric (islemi yapan oturum) korunur. */
    public function digerleriniIptal(int $userId, ?string $haric): void
    {
        DB::table('oauth_access_tokens')->where('user_id', $userId)->where('revoked', 0)
            ->when($haric, fn ($q) => $q->where('id', '!=', $haric))
            ->update(['revoked' => 1, 'updated_at' => now()]);
    }
}
