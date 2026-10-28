<?php

namespace App\Services\Yetki;

use App\Models\Personeller;
use Illuminate\Support\Facades\DB;

/**
 * Tenant (isletme/sube) kapsam servisi.
 *
 * Paylasilan DB'de bir isletme yetkilisinin SADECE kendi bagli oldugu
 * salonlarin kaydina erismesini saglar. Eski sistemde bu kontrol zayifti
 * (cogu uc sadece query'de salon_id filtresi kullaniyordu); burada acik
 * bir "kullanici bu salona bagli mi?" denetimi yapariz.
 *
 * Baglanti kaynaklari (ikisinin birlesimi):
 *   - salon_personelleri.yetkili_id = kullanici  (yetkili_olunan_isletmeler)
 *   - model_has_roles.model_id = kullanici        (rol atamasi olan salonlar)
 */
class KapsamServisi
{
    /** @return int[] Kullanicinin erisebildigi salon_id listesi */
    public function kullaniciSalonlari($yetkiliId): array
    {
        if (! $yetkiliId) {
            return [];
        }

        $personelden = Personeller::where('yetkili_id', $yetkiliId)->pluck('salon_id')->all();
        $rolden = DB::table('model_has_roles')->where('model_id', $yetkiliId)->pluck('salon_id')->all();

        return array_values(array_unique(array_map('intval', array_merge($personelden, $rolden))));
    }

    public function salonaBagliMi($yetkiliId, $salonId): bool
    {
        if (! $yetkiliId || ! $salonId) {
            return false;
        }

        return in_array((int) $salonId, $this->kullaniciSalonlari($yetkiliId), true);
    }
}
