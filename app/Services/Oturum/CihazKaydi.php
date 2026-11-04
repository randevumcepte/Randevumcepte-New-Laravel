<?php

namespace App\Services\Oturum;

use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;

/**
 * Mobil cihazin bildirim kimligi (eski AuthController@login, bildirim_kimlikleri). Kayit yalniz istemci
 * bildirim kimligi gonderdiyse acilir. Flutter eksik degeri "null" metni olarak yollayabiliyor.
 */
class CihazKaydi
{
    /** @param int[] $personelIdleri */
    public function yetkili(array $personelIdleri, ?string $bildirimId, ?string $cihaz, ?string $bundle): void
    {
        if (! $this->gecerli($bildirimId) || ! $personelIdleri) {
            return;
        }
        if (DB::table('bildirim_kimlikleri')->whereIn('isletme_yetkili_id', $personelIdleri)->where('bildirim_id', $bildirimId)->exists()) {
            return;
        }
        foreach ($personelIdleri as $id) {
            ZamanliKayit::ekle('bildirim_kimlikleri', ['isletme_yetkili_id' => $id, 'bildirim_id' => $bildirimId, 'cihaz' => $cihaz, 'app_bundle' => $bundle]);
        }
    }

    public function musteri(int $userId, ?string $bildirimId, ?string $cihaz, ?string $bundle): void
    {
        if (! $this->gecerli($bildirimId)
            || DB::table('bildirim_kimlikleri')->where('user_id', $userId)->where('bildirim_id', $bildirimId)->exists()) {
            return;
        }
        ZamanliKayit::ekle('bildirim_kimlikleri', ['user_id' => $userId, 'bildirim_id' => $bildirimId, 'cihaz' => $cihaz, 'app_bundle' => $bundle]);
    }

    private function gecerli(?string $bildirimId): bool
    {
        return $bildirimId !== null && $bildirimId !== '' && $bildirimId !== 'null';
    }
}
