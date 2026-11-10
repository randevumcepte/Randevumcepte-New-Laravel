<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolon varligi denetimi — TEK nokta. Eski monolitle paylasilan semada bazi kolonlar her ortamda yok; kod buna gore dallanir.
 *
 * SQLite'ta Laravel'in Schema::hasColumn'u pragma_table_xinfo kullanir (SQLite 3.26+); sunucunun test ortamindaki eski
 * SQLite bunu tanimaz. Burada SQLite icin her surumde calisan PRAGMA table_info kullanilir; MySQL'de Schema::hasColumn.
 */
final class Sema
{
    public static function kolonVar(string $tablo, string $kolon): bool
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite') {
            return Schema::hasColumn($tablo, $kolon);
        }
        $ad = $db->getTablePrefix().$tablo;
        foreach ($db->select('PRAGMA table_info("'.str_replace('"', '""', $ad).'")') as $satir) {
            if (strcasecmp((string) ($satir->name ?? ''), $kolon) === 0) {
                return true;
            }
        }

        return false;
    }
}
