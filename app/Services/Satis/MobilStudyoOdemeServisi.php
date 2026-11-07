<?php

namespace App\Services\Satis;

use App\Models\AdisyonHizmetler;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Tahsilat;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobil "Odeme Alindi / Geri Al" — eski `Adisyonlar::tamOde` ve `odemeGeriAl` ile ayni satirlar:
 * kalan olmasa da tek tahsilat basligi yazilir, geri alma adisyonun tum tahsilatlarini siler,
 * olusturan yazilmaz (eski mobil gondermiyordu). Web `AdisyonIslemServisi::studyoOdeme` degismez.
 */
class MobilStudyoOdemeServisi
{
    private const KALEMLER = [
        [AdisyonHizmetler::class, TahsilatHizmetler::class, 'adisyon_hizmet_id'],
        [AdisyonUrunler::class, TahsilatUrunler::class, 'adisyon_urun_id'],
        [AdisyonPaketler::class, TahsilatPaketler::class, 'adisyon_paket_id'],
    ];

    public function isaretle(Adisyonlar $adisyon, bool $alindi): int
    {
        DB::transaction(fn () => $alindi ? $this->tamOde($adisyon) : $this->geriAl($adisyon));

        return $alindi ? 1 : 0;
    }

    private function tamOde(Adisyonlar $a): void
    {
        $paylar = [];
        $toplam = 0;
        foreach (self::KALEMLER as [$kalemModel, $tahsilatModel, $fk]) {
            foreach ($kalemModel::where('adisyon_id', $a->id)->get() as $k) {
                $odenen = $tahsilatModel::where($fk, $k->id)->sum('tutar');
                $kalan = (float) $k->fiyat - (float) $odenen - (float) ($k->indirim_tutari ?? 0);
                if ($kalan > 0) {
                    $paylar[] = [$tahsilatModel, $fk, $k->id, $kalan];
                    $toplam += $kalan;
                }
            }
        }

        $baslik = [
            'adisyon_id'       => $a->id,
            'user_id'          => $a->user_id,
            'salon_id'         => $a->salon_id,
            'odeme_tarihi'     => date('Y-m-d'),
            'odeme_yontemi_id' => DB::table('odeme_yontemleri')->orderBy('id')->value('id') ?: 1,
            'tutar'            => $toplam,
        ];
        if (Schema::hasColumn('tahsilatlar', 'yapilan_odeme')) {
            $baslik['yapilan_odeme'] = $toplam;
        }
        $tahsilat = Tahsilat::create($baslik);
        foreach ($paylar as [$model, $fk, $kalemId, $tutar]) {
            $model::create([$fk => $kalemId, 'tahsilat_id' => $tahsilat->id, 'tutar' => $tutar]);
        }

        $this->odendiYaz($a, 1, date('Y-m-d H:i:s'));
    }

    private function geriAl(Adisyonlar $a): void
    {
        $idler = Tahsilat::where('adisyon_id', $a->id)->pluck('id');
        if ($idler->count()) {
            foreach (self::KALEMLER as [, $tahsilatModel]) {
                $tahsilatModel::whereIn('tahsilat_id', $idler)->delete();
            }
            Tahsilat::where('adisyon_id', $a->id)->delete();
        }
        $this->odendiYaz($a, 0, null);
    }

    private function odendiYaz(Adisyonlar $a, int $odendi, ?string $tarih): void
    {
        if (Schema::hasColumn('adisyonlar', 'odendi')) {
            $a->odendi = $odendi;
        }
        if (Schema::hasColumn('adisyonlar', 'odendi_tarihi')) {
            $a->odendi_tarihi = $tarih;
        }
        $a->save();
    }
}
