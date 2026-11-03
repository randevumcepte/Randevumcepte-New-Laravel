<?php

namespace App\Support;

use App\Exceptions\CakismaException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cift-kayit korumasi (api_idempotency_anahtarlari) — ayni istemci anahtari ikinci
 * kez gelirse is TEKRAR YAPILMAZ, ilk cevap 'replay' => true ile doner.
 *
 * Akis (TahsilatYazServisi ile ayni sozlesme): anahtar + uc satiri isle AYNI
 * transaction'da yazilir; is basarisizsa anahtar da geri alinir (istemci tekrar
 * deneyebilir). Es zamanli ikinci istek benzersizlik ihlaline duser → 409.
 */
final class Idempotency
{
    /**
     * @param  callable():array  $is  Transaction icinde calisir; JSON'a cevrilebilir dizi dondurur.
     */
    public static function calistir(string $uc, string $anahtar, ?int $yetkiliId, ?int $salonId, callable $is): array
    {
        $anahtar = trim($anahtar);
        if ($anahtar === '') {
            throw new CakismaException('idempotency_key zorunlu (çift-kayıt koruması)');
        }

        if ($onceki = self::kayitliYanit($uc, $anahtar)) {
            return $onceki;
        }

        try {
            return DB::transaction(function () use ($uc, $anahtar, $yetkiliId, $salonId, $is) {
                DB::table('api_idempotency_anahtarlari')->insert([
                    'anahtar' => $anahtar, 'uc' => $uc, 'yetkili_id' => $yetkiliId, 'salon_id' => $salonId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $yanit = $is() + ['replay' => false];

                DB::table('api_idempotency_anahtarlari')->where('anahtar', $anahtar)->where('uc', $uc)
                    ->update(['yanit_kodu' => 200, 'yanit_json' => json_encode($yanit), 'updated_at' => now()]);

                return $yanit;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            return self::kayitliYanit($uc, $anahtar)
                ?? throw new CakismaException('Bu istek zaten işleniyor, lütfen tekrar deneyin');
        }
    }

    private static function kayitliYanit(string $uc, string $anahtar): ?array
    {
        $json = DB::table('api_idempotency_anahtarlari')->where('anahtar', $anahtar)->where('uc', $uc)->value('yanit_json');
        if (! $json) {
            return null;
        }

        return ['replay' => true] + json_decode($json, true);
    }
}
