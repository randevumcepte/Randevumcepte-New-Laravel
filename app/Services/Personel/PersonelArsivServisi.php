<?php

namespace App\Services\Personel;

use App\Domain\Personel\HesapSahibiKorumasi;
use App\Models\Personeller;
use App\Models\RandevuHizmetler;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Personel ARSIVLEME (soft-delete) + gelecek-hizmet transfer akisi.
 * Eski ApiController@personelGelecekHizmetler / personelArsivle'den portlandi.
 */
class PersonelArsivServisi
{
    public function __construct(private HesapSahibiDenetimi $sahip) {}

    /**
     * Arsivleme oncesi: personelin gelecek randevulardaki hizmet kalemleri +
     * devralabilecek diger aktif personeller. (Salt-okuma.)
     */
    public function gelecekHizmetler(int $salonId, int $personelId): array
    {
        $personel = Personeller::where('id', $personelId)->where('salon_id', $salonId)->first();
        if (! $personel) {
            return ['sonuc' => 'notfound'];
        }

        $hizmetler = $this->gelecekHizmetSorgusu($salonId, $personelId)
            ->leftJoin('users', 'randevular.user_id', '=', 'users.id')
            ->leftJoin('hizmetler', 'randevu_hizmetler.hizmet_id', '=', 'hizmetler.id')
            ->select(
                'randevu_hizmetler.id as rh_id',
                'randevular.id as randevu_id',
                'randevular.tarih',
                DB::raw('COALESCE(randevu_hizmetler.saat, randevular.saat) as saat'),
                'users.name as musteri',
                'hizmetler.hizmet_adi as hizmet'
            )
            ->orderBy('randevular.tarih')
            ->orderBy('saat')
            ->get();

        $personeller = $this->devralabilecekler($salonId, $personelId)
            ->orderBy('personel_adi')
            ->get(['id', 'personel_adi']);

        return [
            'sonuc'       => 'ok',
            'count'       => $hizmetler->count(),
            'personel_ad' => $personel->personel_adi,
            'hizmetler'   => $hizmetler,
            'personeller' => $personeller,
        ];
    }

    /**
     * Arsivle (soft-delete). Gelecek hizmet varsa transfer ZORUNLU (her kalem gecerli
     * bir aktif personele devredilmeli); eksikse 'aktarim_gerekli' doner, yazma yapilmaz.
     *
     * @param array|string|null $transferler {rh_id: hedef_personel_id} map
     */
    public function arsivle(int $salonId, int $personelId, $transferler, ?int $yapanId = null): array
    {
        return DB::transaction(function () use ($salonId, $personelId, $transferler, $yapanId) {
            $personel = Personeller::where('id', $personelId)->where('salon_id', $salonId)->first();
            if (! $personel) {
                return ['sonuc' => 'notfound'];
            }
            // Arsiv = pasif: hesap sahibi ve kendi satiri korunur (HesapSahibiKorumasi, durum() ile ayni kural).
            $this->sahip->denetle(HesapSahibiKorumasi::ARSIV, $personel->role_id !== null ? (int) $personel->role_id : null,
                $personel->yetkili_id ? (int) $personel->yetkili_id : null, $salonId, $yapanId);

            $gelecekIdler = $this->gelecekHizmetSorgusu($salonId, $personelId)
                ->pluck('randevu_hizmetler.id')
                ->toArray();

            $aktarilan = 0;
            if (count($gelecekIdler) > 0) {
                if (is_string($transferler)) {
                    $transferler = json_decode($transferler, true);
                }
                if (! is_array($transferler)) {
                    $transferler = [];
                }

                $gecerliHedefler = $this->devralabilecekler($salonId, $personelId)
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->toArray();

                foreach ($gelecekIdler as $rhId) {
                    $hedef = isset($transferler[$rhId]) ? (int) $transferler[$rhId] : 0;
                    if ($hedef <= 0 || ! in_array($hedef, $gecerliHedefler, true)) {
                        return [
                            'sonuc' => 'aktarim_gerekli',
                            'count' => count($gelecekIdler),
                            'mesaj' => 'Bu personelin gelecek randevularındaki tüm hizmetler için geçerli bir başka personel seçilmelidir.',
                        ];
                    }
                }

                foreach ($gelecekIdler as $rhId) {
                    RandevuHizmetler::where('id', $rhId)->update(['personel_id' => (int) $transferler[$rhId]]);
                    $aktarilan++;
                }
            }

            $personel->arsivli = true;
            $personel->aktif = false;
            $personel->takvimde_gorunsun = false;
            $personel->save();

            // Arsiv = pasif: baska aktif salonu yoksa oturumu sonlansin (eski web personelsil
            // ile ayni guvenlik; eski API personelArsivle bunu atliyordu).
            BildirimKuyrugu::yaz($salonId, 'personel_pasif', null, $yapanId, ['personel_id' => $personelId]);
            AktiviteLog::yaz($salonId, $yapanId, 'personel_arsiv', 'personel', $personelId, $personel->personel_adi,
                $aktarilan > 0 ? ($aktarilan.' gelecek hizmet başka personele aktarıldı, personel arşivlendi') : 'Personel arşivlendi');

            return ['sonuc' => 'ok', 'aktarilan' => $aktarilan];
        });
    }

    /** Personelin bugunden itibaren bekleyen/onayli randevu hizmet kalemleri (tek tanim). */
    private function gelecekHizmetSorgusu(int $salonId, int $personelId)
    {
        return DB::table('randevu_hizmetler')
            ->join('randevular', 'randevu_hizmetler.randevu_id', '=', 'randevular.id')
            ->where('randevu_hizmetler.personel_id', $personelId)
            ->where('randevular.salon_id', $salonId)
            ->whereIn('randevular.durum', [0, 1])
            ->where('randevular.tarih', '>=', date('Y-m-d'));
    }

    /** Hizmetleri devralabilecek diger aktif personeller (tek tanim). */
    private function devralabilecekler(int $salonId, int $personelId)
    {
        return Personeller::where('salon_id', $salonId)
            ->where('id', '!=', $personelId)
            ->where('aktif', 1);
    }
}
