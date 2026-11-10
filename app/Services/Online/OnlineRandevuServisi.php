<?php

namespace App\Services\Online;

use App\Domain\Randevu\HizmetZinciri;
use App\Exceptions\CakismaException;
use App\Models\Randevular;
use App\Services\MusteriHesap\PortfoyKaydi;
use App\Services\Randevu\RandevuKayitServisi;
use App\Support\Idempotency;
use App\Support\RandevuYazma;
use Illuminate\Support\Facades\DB;

/**
 * Musterinin kendi randevusunu olusturmasi (mini site + musteri uygulamasi TEK yol).
 * Eski CustomerController@randevuekle ve v1 randevuekleguncelle(kaynak=uygulama) karsiligi.
 *
 * Kimlik token'dan. Sure, fiyat, personel uygunlugu ve oda SUNUCUDA belirlenir (v1 istemciden
 * aliyordu). Durum her zaman 'bekliyor'; asil yazma RandevuKayitServisi'nde (seans havuzu, outbox,
 * log tekrar yazilmaz). Saat, personel kilidi altinda yeniden hesaplanir: ayni saate gelen iki
 * istekten biri cakisma alir.
 */
class OnlineRandevuServisi
{
    public function __construct(
        private OnlineSalonKapisi $kapi,
        private OnlineMusaitlikServisi $musaitlik,
        private RandevuKayitServisi $kayit,
        private PortfoyKaydi $portfoy,
        private OnlineRandevuSiniri $sinir,
    ) {}

    /**
     * @param  array{tarih:string,saat:string,kalemler:array,not?:?string,kaynak:string,idempotency_key?:?string,mobil?:bool,_hp?:?string,form_sure_sn?:?int}  $g
     */
    /** @param  ?string  $misafirIp  yalniz kimliksiz / misafir yolda (IP siniri); kimlikli musteri isteginde null */
    public function olustur(int $salonId, int $userId, array $g, ?string $misafirIp = null): array
    {
        // Online kapali veya lisansi dolmus salona her yoldan kapali. Mobil bayrak yalniz gizli personel secimini acar.
        $salon = $this->kapi->randevuyaAcikSalon($salonId);
        if (! RandevuYazma::acik($salonId)) {
            throw new CakismaException(RandevuYazma::KAPALI_MESAJ);
        }

        // Sinir is icinde: idempotent tekrar (kayitli cevap) sinira takilmaz, yalniz basarili olusturma sayilir.
        $is = function () use ($salon, $salonId, $userId, $g, $misafirIp) {
            $this->sinir->denetle($salonId, $userId, $misafirIp, $g);
            $sonuc = DB::transaction(fn () => $this->yaz($salon, $userId, $g));
            $this->sinir->say($salonId, $userId, $misafirIp);

            return $sonuc;
        };
        $anahtar = trim((string) ($g['idempotency_key'] ?? ''));

        return $anahtar !== ''
            ? Idempotency::calistir('musteri_randevu', 'u'.$userId.':'.$anahtar, null, $salonId, $is)
            : $is() + ['replay' => false];
    }

    private function yaz(object $salon, int $userId, array $g): array
    {
        $salonId = (int) $salon->id;
        DB::table('salon_personelleri')->whereIn('id', $this->musaitlik->adayPersoneller($salonId, $g['kalemler']))
            ->orderBy('id')->lockForUpdate()->pluck('id');

        $yerlesim = $this->musaitlik->yerlesim($salon, $g['tarih'], $g['saat'], $g['kalemler']);
        if ($yerlesim === null) {
            throw new CakismaException('Seçtiğiniz saat artık uygun değil. Lütfen başka bir saat seçiniz.');
        }

        $this->portfoy->garantile($salonId, $userId);

        $detay = $this->musaitlik->hizmetDetaylari($salonId, array_map(fn ($k) => (int) $k['hizmet_id'], $g['kalemler']));
        $hizmetler = array_map(function ($y) use ($g, $detay) {
            $girdi = array_values($g['kalemler'])[$y['index']];
            $hizmetId = (int) $girdi['hizmet_id'];

            return [
                'hizmet_id'         => $hizmetId,
                'personel_id'       => $y['personel_id'],
                'oda_id'            => $y['oda_id'],
                'sure_dk'           => $y['sure'],
                'fiyat'             => (float) ($detay[$hizmetId]->baslangic_fiyat ?? 0),
                'adisyon_paket_id'  => $girdi['adisyon_paket_id'] ?? null,
                'adisyon_hizmet_id' => $girdi['adisyon_hizmet_id'] ?? null,
            ];
        }, $yerlesim);

        $sonuc = $this->kayit->kaydet($salonId, [
            'user_id'        => $userId,
            'randevu_tarihi' => $g['tarih'],
            'randevu_saati'  => HizmetZinciri::saat($yerlesim[0]['bas']),
            'hizmetler'      => $hizmetler,
            'notlar'         => $g['not'] ?? null,
            'durum'          => Randevular::BEKLENIYOR,
            'kaynak'         => $g['kaynak'],
        ], null, true);

        if (empty($sonuc['yazildi'])) {
            throw new CakismaException($sonuc['cakisanunsurlar'] ?? 'Seçtiğiniz saat artık uygun değil.');
        }

        return [
            'randevu_id' => (int) $sonuc['randevu_idler'][0],
            'durum'      => Randevular::BEKLENIYOR,
            'tarih'      => $g['tarih'],
            'saat'       => HizmetZinciri::saat($yerlesim[0]['bas']),
        ];
    }
}
