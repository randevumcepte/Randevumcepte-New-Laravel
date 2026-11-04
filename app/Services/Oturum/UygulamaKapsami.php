<?php

namespace App\Services\Oturum;

use Illuminate\Support\Facades\DB;

/**
 * Istemcinin (app_bundle) hangi salonlari gorecegi. Eski AuthController@login kurallari:
 *   bundle yok (web)  → yetkilinin tum aktif salonlari
 *   ana uygulama      → uyelik_turu 3 disi salonlar
 *   marka uygulamasi  → yalniz markanin salonlari
 */
class UygulamaKapsami
{
    public function anaUygulamaMi(?string $bundle): bool
    {
        return $bundle === config('oturum.ana_uygulama_bundle');
    }

    public function markaMi(?string $bundle): bool
    {
        return $bundle !== null && $bundle !== '' && ! $this->anaUygulamaMi($bundle);
    }

    /** @return int[] */
    public function markaSalonlari(string $bundle): array
    {
        return DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Yetkilinin aktif personel satirlari, salon basina tek satir (ayni salonda birden cok personel olabilir). */
    public function yetkiliPersonelSatirlari(int $yetkiliId, ?string $bundle): array
    {
        return DB::table('salon_personelleri as p')->join('salonlar as s', 's.id', '=', 'p.salon_id')
            ->where('p.yetkili_id', $yetkiliId)->where('p.aktif', 1)
            ->when($this->anaUygulamaMi($bundle), fn ($q) => $q->where('s.uyelik_turu', '!=', 3))
            ->when($this->markaMi($bundle), fn ($q) => $q->where('s.app_bundle', $bundle))
            ->orderBy('p.id')->get(['p.id', 'p.salon_id', 'p.yetkili_id', 'p.role_id', 'p.aktif'])
            ->unique('salon_id')->values()->all();
    }
}
