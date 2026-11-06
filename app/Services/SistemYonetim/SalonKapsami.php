<?php

namespace App\Services\SistemYonetim;

use App\Exceptions\BulunamadiException;
use App\Exceptions\YetkisizErisimException;
use App\Models\SistemYoneticisi;
use Illuminate\Support\Facades\DB;

/** Destek rolu yalniz kendisine atanmis salonlari gorur; kural tek yerde (eski kodda ekran ekran kopyaydi). */
class SalonKapsami
{
    /** Salon sorgusuna kapsami uygular. $kolon join'li sorgular icin nitelenebilir. */
    public function sorguyaUygula($sorgu, SistemYoneticisi $yonetici, string $kolon = 'musteri_yetkili_id')
    {
        return $yonetici->rol()->salonKapsamliMi() ? $sorgu->where($kolon, $yonetici->id) : $sorgu;
    }

    /** Salon satiri: yoksa 404, kapsam disiysa 403. */
    public function salonuGetir(int $salonId, SistemYoneticisi $yonetici, array $kolonlar = ['*']): object
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first($kolonlar === ['*'] ? $kolonlar : [...$kolonlar, 'musteri_yetkili_id']);
        if (! $salon) {
            throw new BulunamadiException('Salon bulunamadı');
        }
        if ($yonetici->rol()->salonKapsamliMi() && (int) $salon->musteri_yetkili_id !== (int) $yonetici->id) {
            throw new YetkisizErisimException('Bu salonu görme yetkiniz yok.');
        }

        return $salon;
    }
}
