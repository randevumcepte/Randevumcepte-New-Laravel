<?php

namespace App\Services\Hesabim\Hizmet;

use App\Domain\Hesabim\HizmetKarti;
use App\Domain\Hesabim\UyelikKatalogu;
use Illuminate\Support\Facades\DB;

/** Dinamik Onam Formu kartı (Premium) — salonda şablon varsa. */
class OnamFormKaynagi implements HizmetKaynagi
{
    public function kartlar(object $salon): array
    {
        if (! UyelikKatalogu::premiumMu($salon->uyelik_turu ?? null)) {
            return [];
        }
        $adet = DB::table('formtaslaklari')->where('salon_id', $salon->id)->where('is_dinamik', 1)->count();

        return $adet > 0
            ? [HizmetKarti::pakete('onam', 'Dinamik Onam Formu', $adet.' form tanımlı', 'fa-file-text-o', 'bi-file-earmark-text', 'mavi')]
            : [];
    }
}
