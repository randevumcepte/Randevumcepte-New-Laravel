<?php

namespace App\Services\MusteriHesap;

use App\Exceptions\YetkisizErisimException;
use Illuminate\Support\Facades\DB;

/**
 * Musterinin bir salon adina yazabilmesi: salon portfoyunde olmali ya da ayni markanin bir subesinde portfoy kaydi
 * olmali (marka girisi tum subeleri portfoye ekler; eski oturumlarda satir eksik olabilir). Marka salonun kaydindan
 * okunur, istemcinin gonderdigi bundle'a guvenilmez. Ana uygulama marka sayilmaz: tum salonlar onun altindadir.
 */
class MusteriSalonKapisi
{
    public function denetle(int $userId, int $salonId): void
    {
        if (DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->exists()) {
            return;
        }
        $bundle = DB::table('salonlar')->where('id', $salonId)->value('app_bundle');
        $markada = $bundle && $bundle !== config('oturum.ana_uygulama_bundle')
            && DB::table('musteri_portfoy as p')->join('salonlar as s', 's.id', '=', 'p.salon_id')
                ->where('p.user_id', $userId)->where('s.app_bundle', $bundle)->exists();
        if (! $markada) {
            throw new YetkisizErisimException('Bu işletme için yetkiniz yok.');
        }
    }
}
