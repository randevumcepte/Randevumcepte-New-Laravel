<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\SistemYonetim\SaglikSkoru;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Saglik skoru sinyallerini toplar; puanlama Domain\SaglikSkoru'da. isletmeyetkilileri'nde son giris kolonu yok:
 * yetkilinin updated_at'i tek elde edilebilir "son giris" sinyalidir.
 */
class SalonSaglikServisi
{
    private const ACIK_TALEP = ['acik', 'islemde', 'bekliyor'];

    private const ONBELLEK_SN = 300;

    /**
     * Toplu sinyal: salon basina sabit sorgu sayisi degil, tum salonlar icin 4 sorgu (risk listesi de bunu kullanir).
     *
     * @param  list<int>  $salonIdler
     * @return array<int,array{son_giris:?string,son_randevu:?string,hacim_30g:int,acik_ticket:int,sikayet:int}>
     */
    public function sinyaller(array $salonIdler): array
    {
        if (! $salonIdler) {
            return [];
        }
        $sinir = now()->subDays(30)->startOfDay()->toDateTimeString();

        $girisler = DB::table('salon_personelleri')->join('isletmeyetkilileri', 'salon_personelleri.yetkili_id', '=', 'isletmeyetkilileri.id')
            ->whereIn('salon_personelleri.salon_id', $salonIdler)->groupBy('salon_personelleri.salon_id')
            ->selectRaw('salon_personelleri.salon_id as salon_id, MAX(isletmeyetkilileri.updated_at) as son')->pluck('son', 'salon_id');
        $randevular = DB::table('randevular')->whereIn('salon_id', $salonIdler)->groupBy('salon_id')
            ->selectRaw('salon_id, MAX(created_at) as son, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as adet', [$sinir])->get()->keyBy('salon_id');
        $talepler = DB::table('sistemyonetim_destek_talepleri')->whereIn('salon_id', $salonIdler)->whereIn('durum', self::ACIK_TALEP)
            ->groupBy('salon_id')->selectRaw('salon_id, COUNT(*) as adet')->pluck('adet', 'salon_id');
        $sikayetler = DB::table('sistemyonetim_salon_notlari')->whereIn('salon_id', $salonIdler)->where('tip', 'sikayet')->where('created_at', '>=', $sinir)
            ->groupBy('salon_id')->selectRaw('salon_id, COUNT(*) as adet')->pluck('adet', 'salon_id');

        $sonuc = [];
        foreach ($salonIdler as $id) {
            $randevu = $randevular[$id] ?? null;
            $sonuc[$id] = [
                'son_giris'   => $girisler[$id] ?? null,
                'son_randevu' => $randevu->son ?? null,
                'hacim_30g'   => (int) ($randevu->adet ?? 0),
                'acik_ticket' => (int) ($talepler[$id] ?? 0),
                'sikayet'     => (int) ($sikayetler[$id] ?? 0),
            ];
        }

        return $sonuc;
    }

    /** Tek salonun skoru. Askida durumu onbellege girmez: askiya alma aninda gorunur. */
    public function salonIcin(object $salon): array
    {
        if ((int) $salon->askiya_alindi === 1) {
            return SaglikSkoru::askida($salon->askiya_alma_sebebi);
        }
        $id = (int) $salon->id;
        $sinyaller = Cache::remember("sy.v2.saglik.{$id}", self::ONBELLEK_SN, fn () => $this->sinyaller([$id])[$id]);

        return SaglikSkoru::hesapla($sinyaller, (bool) $salon->whatsapp_aktif) + ['sinyaller' => $sinyaller];
    }
}
