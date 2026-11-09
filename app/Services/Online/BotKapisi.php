<?php

namespace App\Services\Online;

use App\Exceptions\GecersizIslemException;

/**
 * Kimliksiz / musteri web formlarinin bot kapisi (eski randevuonaylaDirekt): gizli bal kupu alani (_hp) dolu ya da
 * form acildiktan sonra cok hizli (form_sure_sn < online.kayit_min_saniye) gonderildiyse islem yapilmaz.
 * Musteri kaydi ve mini site randevu olusturma ORTAK kullanir. Sure gonderilmediyse sure kurali uygulanmaz
 * (uygulama istemcisi form suresi gondermez).
 */
final class BotKapisi
{
    /** @param  array{_hp?:?string, form_sure_sn?:int|string|null}  $g */
    public static function denetle(array $g): void
    {
        if (self::botMu($g, (int) config('online.kayit_min_saniye'))) {
            throw new GecersizIslemException('İşlem doğrulanamadı. Lütfen tekrar deneyiniz.');
        }
    }

    /** Saf kural (test edilebilir). */
    public static function botMu(array $g, int $minSaniye): bool
    {
        $sure = isset($g['form_sure_sn']) && $g['form_sure_sn'] !== '' ? (int) $g['form_sure_sn'] : null;

        return trim((string) ($g['_hp'] ?? '')) !== '' || ($sure !== null && $sure < $minSaniye);
    }
}
