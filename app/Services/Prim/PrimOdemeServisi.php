<?php

namespace App\Services\Prim;

use App\Domain\Rapor\PrimOdemeKurali;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Masraf;
use App\Models\MasrafKategorisi;
use App\Models\PersonelMaasOdemesi;
use App\Models\PersonelPrimHareketi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Personel odemesi ve bonus/kesinti yazma — eski ApiController primOdeApi, primOdemeSilApi, primHareketEkleApi,
 * primHareketSilApi ile AYNI satirlar. Odeme kaydi ile otomatik kasa kaydi tek islemdir: biri yazilamazsa ikisi de yazilmaz.
 */
class PrimOdemeServisi
{
    /** @return array{odeme_id:int} */
    public function ode(int $salonId, int $yetkiliId, array $g): array
    {
        $personel = $this->personel($salonId, $g['personel_id'] ?? null);
        $donem = $g['donem'] ?? null;
        if (! PrimOdemeKurali::donemGecerli($donem)) {
            throw new GecersizIslemException('Geçersiz dönem.');
        }
        $tutar = $this->tutar($g['tutar'] ?? null);
        $tarih = PrimOdemeKurali::tarih($g['odeme_tarihi'] ?? null, date('Y-m-d'));
        $tip = PrimOdemeKurali::odemeTipi($g['odeme_tipi'] ?? null);
        $primGun = PrimOdemeKurali::primGun($tip, $g['prim_gun'] ?? null);

        return DB::transaction(function () use ($salonId, $yetkiliId, $g, $personel, $donem, $tutar, $tarih, $tip, $primGun) {
            $odeme = PersonelMaasOdemesi::create(array_filter([
                'prim_gun' => $primGun,
            ]) + [
                'personel_id'        => $personel->id,
                'salon_id'           => $salonId,
                'donem'              => $donem,
                'tutar'              => $tutar,
                'odeme_tipi'         => $tip,
                'odeme_tarihi'       => $tarih,
                'odeme_yontemi'      => PrimOdemeKurali::kes($g['odeme_yontemi'] ?? '', 60),
                'aciklama'           => PrimOdemeKurali::kes($g['aciklama'] ?? '', 300),
                'ekleyen_yetkili_id' => $yetkiliId,
            ]);

            Masraf::create([
                'personel_maas_odemesi_id' => $odeme->id,
                'salon_id'                 => $salonId,
                'harcayan_id'              => $personel->id,
                'masraf_kategori_id'       => MasrafKategorisi::firstOrCreate(['kategori' => PrimOdemeKurali::MASRAF_KATEGORISI])->id,
                'tarih'                    => $tarih,
                'tutar'                    => $tutar,
                'odeme_yontemi_id'         => (int) ($g['odeme_yontemi_id'] ?? 1),
                'aciklama'                 => PrimOdemeKurali::masrafAciklamasi($tip, $donem, $g['aciklama'] ?? null),
                'notlar'                   => PrimOdemeKurali::masrafNotu($personel->personel_adi, $tip, $donem),
            ]);

            AktiviteLog::yaz($salonId, $yetkiliId, 'personel_odeme', 'personel', (int) $personel->id,
                $personel->personel_adi.' - Tutar: '.$tutar, 'Personele ödeme yapıldı ('.$tip.' / '.$donem.')');

            return ['odeme_id' => (int) $odeme->id];
        });
    }

    /** Bagli kasa kaydi da silinir. */
    public function odemeSil(int $salonId, int $yetkiliId, int $id): void
    {
        DB::transaction(function () use ($salonId, $yetkiliId, $id) {
            $kayit = PersonelMaasOdemesi::where('id', $id)->where('salon_id', $salonId)->first()
                ?? throw new BulunamadiException('Ödeme kaydı bulunamadı.');
            Masraf::where('personel_maas_odemesi_id', $kayit->id)->where('salon_id', $salonId)->delete();
            $kayit->delete();
            AktiviteLog::yaz($salonId, $yetkiliId, 'prim_odeme_sil', 'prim_odeme', $id, null, 'Personel maas/prim odemesi silindi');
        });
    }

    public function hareketEkle(int $salonId, int $yetkiliId, array $g): array
    {
        $personel = $this->personel($salonId, $g['personel_id'] ?? null);
        $tip = PrimOdemeKurali::hareketTipi($g['tip'] ?? null);
        $tutar = $this->tutar($g['tutar'] ?? null);

        return DB::transaction(function () use ($salonId, $yetkiliId, $g, $personel, $tip, $tutar) {
            $hareket = PersonelPrimHareketi::create([
                'personel_id'        => $personel->id,
                'salon_id'           => $salonId,
                'tarih'              => PrimOdemeKurali::tarih($g['tarih'] ?? null, date('Y-m-d')),
                'tip'                => $tip,
                'tutar'              => $tutar,
                'aciklama'           => PrimOdemeKurali::kes($g['aciklama'] ?? '', 300),
                'ekleyen_yetkili_id' => $yetkiliId,
            ]);
            AktiviteLog::yaz($salonId, $yetkiliId, 'prim_hareket_ekle', 'prim_hareket', (int) $personel->id, $personel->personel_adi,
                'Prim bonus/kesinti hareketi eklendi', ['tip' => $tip, 'tutar' => $tutar]);

            return ['hareket_id' => (int) $hareket->id];
        });
    }

    public function hareketSil(int $salonId, int $yetkiliId, int $id): void
    {
        DB::transaction(function () use ($salonId, $yetkiliId, $id) {
            $hareket = PersonelPrimHareketi::where('id', $id)->where('salon_id', $salonId)->first()
                ?? throw new BulunamadiException('Kayıt bulunamadı.');
            $hareket->delete();
            AktiviteLog::yaz($salonId, $yetkiliId, 'prim_hareket_sil', 'prim_hareket', $id, null, 'Prim bonus/kesinti hareketi silindi');
        });
    }

    private function personel(int $salonId, mixed $personelId): object
    {
        return DB::table('salon_personelleri')->where('id', (int) $personelId)->where('salon_id', $salonId)->first(['id', 'personel_adi'])
            ?? throw new GecersizIslemException('Personel bulunamadı.');
    }

    private function tutar(mixed $ham): float
    {
        $tutar = PrimOdemeKurali::tutar($ham);
        if ($tutar <= 0) {
            throw new GecersizIslemException("Tutar 0'dan büyük olmalı.");
        }

        return $tutar;
    }
}
