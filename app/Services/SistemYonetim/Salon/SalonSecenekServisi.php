<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\SistemYonetim\SalonIzinleri;
use App\Domain\SistemYonetim\SalonNotTipi;
use App\Domain\SistemYonetim\SistemRolu;
use App\Domain\SistemYonetim\UyelikKurallari;
use Illuminate\Support\Facades\DB;

/** Salon ekranlarinin secim listeleri ve gorunum izinleri. */
class SalonSecenekServisi
{
    public function hepsi(SistemRolu $rol): array
    {
        return [
            'izinler'       => SalonIzinleri::rolIcin($rol),
            'iller'         => DB::table('il')->orderBy('il_adi')->get(['id', 'il_adi']),
            'ilceler'       => DB::table('ilce')->orderBy('ilce_adi')->get(['id', 'il_id', 'ilce_adi']),
            'salon_turleri' => DB::table('salon_turu')->orderBy('salon_turu_adi')->get(['id', 'salon_turu_adi']),
            'temsilciler'   => DB::table('sistemyoneticileri')->orderBy('name')->get(['id', 'name']),
            'not_tipleri'   => SalonNotTipi::secenekler(),
        ] + UyelikKurallari::secenekler();
    }
}
