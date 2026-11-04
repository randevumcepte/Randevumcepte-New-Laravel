<?php

namespace App\Services\Ders;

use App\Exceptions\GecersizIslemException;
use Illuminate\Support\Facades\DB;

/**
 * Ders formlarinin referans verisi + kiraci (salon) dogrulamasi — oturum ve sablon ORTAK.
 * Eski uclar govdeden gelen personel/hizmet/musteri id'sini salonla eslestirmiyordu.
 */
class DersReferansServisi
{
    /** Secenekler + hizmet↔egitmen eslesmesi (iki yonlu filtre). Alan adlari Flutter modeliyle ayni. */
    public function secenekler(int $salonId): array
    {
        return [
            'personeller' => DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
                ->orderBy('takvim_sirasi')->get(['id', 'personel_adi'])->all(),
            'hizmetler' => DB::table('salon_sunulan_hizmetler as sh')->join('hizmetler as h', 'sh.hizmet_id', '=', 'h.id')
                ->where('sh.salon_id', $salonId)->where('sh.aktif', 1)->orderBy('h.hizmet_adi')->distinct()
                ->get(['h.id', 'h.hizmet_adi'])->all(),
            'eslesme' => DB::table('personel_sunulan_hizmetler as ph')->join('salon_personelleri as p', 'p.id', '=', 'ph.personel_id')
                ->where('p.salon_id', $salonId)->where('p.aktif', 1)->get(['ph.personel_id', 'ph.hizmet_id'])->all(),
        ];
    }

    public function personelHizmetDogrula(int $salonId, ?int $personelId, ?int $hizmetId): void
    {
        if ($personelId && ! DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Seçilen eğitmen bu işletmeye ait değil.');
        }
        if ($hizmetId && ! DB::table('salon_sunulan_hizmetler')->where('hizmet_id', $hizmetId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Seçilen hizmet bu işletmede sunulmuyor.');
        }
    }

    public function musteriDogrula(int $salonId, int $userId): void
    {
        if (! DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->exists()) {
            throw new GecersizIslemException('Müşteri bu işletmede kayıtlı değil.');
        }
    }
}
