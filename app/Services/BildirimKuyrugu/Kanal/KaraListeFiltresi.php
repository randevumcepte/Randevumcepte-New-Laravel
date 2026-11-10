<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use App\Support\Sema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merkezi kara liste suzgeci; eski KaraListeServisi ile birebir. musteri_portfoy.kara_liste=1 olan musteriye SMS, WhatsApp
 * ve push gitmez; musterinin kendi aksiyonu olan guvenlik mesajlari muaftir. Hata olursa engellemez (fail-open).
 *
 * Telefondan aramada kayitta gecen uc bicim de aranir: 905..., 05..., 5... Eski kod (ltrim '9') yalniz '05...' bicimini
 * buluyordu; '5XXXXXXXXX' kayitli kara liste musterisine user_id'siz giden mesaj (randevu, ders, form) suzulmuyordu.
 */
class KaraListeFiltresi
{
    public const MUAF_TIPLER = ['sifre_sifirlama', 'geldi_dogrulama_kodu', 'cark_kodu', 'kara_liste_bildirim'];

    /** Eskideki gibi surec boyunca onbellekli (queue:work'te yeniden baslatmaya kadar). */
    private static array $salonAktif = [];

    public static function tipMuaf(?string $gonderimTipi): bool
    {
        return $gonderimTipi && in_array($gonderimTipi, self::MUAF_TIPLER, true);
    }

    public static function salonAktifMi(mixed $salonId): bool
    {
        $salonId = (int) $salonId;
        if ($salonId <= 0) {
            return true;
        }
        if (array_key_exists($salonId, self::$salonAktif)) {
            return self::$salonAktif[$salonId];
        }
        try {
            if (! Sema::kolonVar('salonlar', 'kara_liste_aktif')) {
                return self::$salonAktif[$salonId] = true;
            }
            $v = DB::table('salonlar')->where('id', $salonId)->value('kara_liste_aktif');

            return self::$salonAktif[$salonId] = $v === null ? true : (int) $v === 1;
        } catch (\Throwable) {
            return self::$salonAktif[$salonId] = true;
        }
    }

    /** @return bool true = engelli */
    public static function engelliMi(mixed $salonId, ?string $telefon = null, ?int $userId = null, ?string $gonderimTipi = null): bool
    {
        if (self::tipMuaf($gonderimTipi)) {
            return false;
        }
        $salonId = (int) $salonId;
        if ($salonId <= 0 || ! self::salonAktifMi($salonId)) {
            return false;
        }

        try {
            if ($userId) {
                return self::portfoyKara($salonId, [$userId]);
            }
            $norm = KanalTelefonu::normalize($telefon);
            if ($norm === null) {
                return false;
            }
            $userIds = DB::table('users')->whereIn('cep_telefon', self::bicimler($norm))->pluck('id')->all();

            return $userIds ? self::portfoyKara($salonId, $userIds) : false;
        } catch (\Throwable $e) {
            Log::warning('[KARA-LISTE] engelliMi hata: '.$e->getMessage());

            return false;
        }
    }

    /**
     * [['to'=>..., 'message'=>..., 'user_id'?=>...], ...] dizisinden kara listedekileri cikarir.
     *
     * @return array suzulmus dizi; anahtarlar yeniden numaralanir
     */
    public static function topluSuz(mixed $salonId, array $mesajlar, ?string $gonderimTipi = null): array
    {
        if (self::tipMuaf($gonderimTipi)) {
            return $mesajlar;
        }
        $salonId = (int) $salonId;
        if ($salonId <= 0 || ! $mesajlar || ! self::salonAktifMi($salonId)) {
            return $mesajlar;
        }

        try {
            $telefonlar = [];
            $hazirUserIds = [];
            foreach ($mesajlar as $m) {
                if (! empty($m['user_id'])) {
                    $hazirUserIds[(int) $m['user_id']] = true;

                    continue;
                }
                $t = KanalTelefonu::normalize($m['to'] ?? null);
                if ($t !== null) {
                    $telefonlar[$t] = true;
                }
            }

            $telUserIdleri = self::telefonKullanicilari(array_map('strval', array_keys($telefonlar)));
            $tumUserIds = array_keys($hazirUserIds);
            foreach ($telUserIdleri as $ids) {
                $tumUserIds = array_merge($tumUserIds, $ids);
            }
            $tumUserIds = array_values(array_unique(array_map('intval', $tumUserIds)));
            if (! $tumUserIds) {
                return $mesajlar;
            }

            $kara = DB::table('musteri_portfoy')->whereIn('user_id', $tumUserIds)->where('salon_id', $salonId)->where('kara_liste', 1)->pluck('user_id')->all();
            if (! $kara) {
                return $mesajlar;
            }
            $karaKume = array_flip(array_map('intval', $kara));

            $kalan = [];
            foreach ($mesajlar as $m) {
                if (! self::mesajEngelli($m, $karaKume, $telUserIdleri)) {
                    $kalan[] = $m;
                }
            }
            if (count($kalan) !== count($mesajlar)) {
                Log::info('[KARA-LISTE] toplu suzuldu', [
                    'salon_id' => $salonId, 'tip' => $gonderimTipi,
                    'gelen' => count($mesajlar), 'dusen' => count($mesajlar) - count($kalan), 'kalan' => count($kalan),
                ]);
            }

            return $kalan;
        } catch (\Throwable $e) {
            Log::warning('[KARA-LISTE] topluSuz hata: '.$e->getMessage());

            return $mesajlar;
        }
    }

    /** @return array<string, list<int>> normalize telefon → kullanici id'leri */
    private static function telefonKullanicilari(array $telefonlar): array
    {
        if (! $telefonlar) {
            return [];
        }
        $bicimler = array_values(array_unique(array_merge(...array_map(fn ($t) => self::bicimler($t), $telefonlar))));
        $satirlar = DB::table('users')->whereIn('cep_telefon', $bicimler)->get(['id', 'cep_telefon']);

        $harita = [];
        foreach ($satirlar as $u) {
            $tn = KanalTelefonu::normalize($u->cep_telefon);
            if ($tn !== null) {
                $harita[$tn][] = (int) $u->id;
            }
        }

        return $harita;
    }

    /** @return list<string> normalize numaranin kayitta gecebilecek bicimleri: 905..., 05..., 5... */
    private static function bicimler(string $norm): array
    {
        $yerel = str_starts_with($norm, '90') ? substr($norm, 2) : $norm;

        return array_values(array_unique([$norm, '0'.$yerel, $yerel]));
    }

    private static function mesajEngelli(array $m, array $karaKume, array $telUserIdleri): bool
    {
        if (! empty($m['user_id']) && isset($karaKume[(int) $m['user_id']])) {
            return true;
        }
        $tn = KanalTelefonu::normalize($m['to'] ?? null);
        foreach ($tn !== null ? ($telUserIdleri[$tn] ?? []) : [] as $uid) {
            if (isset($karaKume[$uid])) {
                return true;
            }
        }

        return false;
    }

    private static function portfoyKara(int $salonId, array $userIds): bool
    {
        return DB::table('musteri_portfoy')->whereIn('user_id', $userIds)->where('salon_id', $salonId)->where('kara_liste', 1)->exists();
    }
}
