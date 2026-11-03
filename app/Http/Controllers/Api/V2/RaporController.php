<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\Rapor\RaporSecenekServisi;
use App\Services\Rapor\SatisRaporServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Rapor secenekleri + SATIS RAPORLARI (eski isletmeyonetim/raporlar + *RaporFiltre,
 * hizmetiAlanMusteriler; mobil v1 hizmet/urun/paket/personelRaporlari + *MusteriListesi).
 * Web /v2/raporlar ve Flutter AYNI uclari kullanir.
 *
 *   GET /rapor/secenekler?salon_id=&ekran=satis|isletme|kasa
 *   GET /rapor/satis?salon_id=&sekme=tum|hizmet|urun|paket&donem=&baslangic=&bitis=&personel_id=&ara=&sirala=&yon=&page=&per_page=
 *   GET /rapor/satis/personel?salon_id=&donem=...           (gelir + prim, Prim & Hak Edis formulu)
 *   GET /rapor/satis/{tur}/{kalem}/musteriler?salon_id=&donem=...&personel_id=   (tur: hizmet|urun|paket|seans)
 *
 * KVKK: kimlik + kapsam + rapor.satis (eski filtre uclarinda hic yoktu). Personel (rol 5)
 * yalniz kendi satislarini gorur (personel_id sunucuda zorlanir). Tahsil edilen / kar
 * rapor.ciro_kar_gor yoksa sunucuda gizlenir. Telefon musteri.telefon_gor ile.
 */
class RaporController extends RaporTabanController
{
    public function __construct(private RaporSecenekServisi $secenek, private SatisRaporServisi $satis) {}

    public function secenekler(Request $r)
    {
        $ekran = (string) $r->input('ekran');
        if (! in_array($ekran, RaporSecenekServisi::EKRANLAR, true)) {
            throw new GecersizIslemException('ekran şunlardan biri olmalı: '.implode(', ', RaporSecenekServisi::EKRANLAR));
        }
        [$uid, $salonId] = match ($ekran) {
            'satis'   => $this->salonYetki($r, 'rapor.satis'),
            'isletme' => $this->salonYetki($r, ['rapor.satis', 'rapor.personel_performans', 'rapor.musteri'], true),
            'kasa'    => $this->salonYetki($r, RaporSecenekServisi::KASA_GIRIS),
        };

        return ApiCevap::basarili($this->secenek->getir($uid, $salonId, $ekran));
    }

    public function kalemler(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.satis');
        $sekme = (string) $r->input('sekme', 'tum');
        if (! array_key_exists($sekme, SatisRaporServisi::SEKME_TURLERI)) {
            throw new GecersizIslemException('sekme şunlardan biri olmalı: '.implode(', ', array_keys(SatisRaporServisi::SEKME_TURLERI)));
        }

        $maskeli = ! $this->yetkiVar($uid, $salonId, 'rapor.ciro_kar_gor');

        return ApiCevap::basarili($this->satis->kalemler(
            $salonId, $this->donem($r), $sekme, $this->personel($r, $uid, $salonId),
            $this->liste($r, $this->siralanabilir(SatisRaporServisi::SIRALANABILIR, $maskeli), 'gelir'), $maskeli,
        ));
    }

    public function personeller(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.satis');
        if ($this->secenek->zorunluPersonel($uid, $salonId) !== null) {
            throw new GecersizIslemException('Personel raporu personel hesaplarına kapalıdır');
        }

        return ApiCevap::basarili($this->satis->personeller(
            $salonId, $this->donem($r), $this->liste($r, SatisRaporServisi::PERSONEL_SIRALANABILIR, 'toplam_gelir'),
        ));
    }

    public function musteriler(Request $r, string $tur, int $kalem)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.satis');
        $maskeli = ! $this->yetkiVar($uid, $salonId, 'rapor.ciro_kar_gor');

        return ApiCevap::basarili($this->satis->musteriler(
            $salonId, $this->donem($r), $tur, $kalem, $this->personel($r, $uid, $salonId),
            $this->liste($r, $this->siralanabilir(SatisRaporServisi::MUSTERI_SIRALANABILIR, $maskeli), 'satis_tarihi'),
            $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'), $maskeli,
        ));
    }

    /** Maskeliyken gizli alanlara gore siralama yok (sira gizli degeri sizdirir). */
    private function siralanabilir(array $alanlar, bool $maskeli): array
    {
        return $maskeli ? array_values(array_diff($alanlar, SatisRaporServisi::MASKELI_ALANLAR)) : $alanlar;
    }

    /** Rol 5 → kendi personel kaydi (istekteki personel_id yok sayilir); yonetici → opsiyonel filtre. */
    private function personel(Request $r, int $uid, int $salonId): ?int
    {
        return $this->secenek->zorunluPersonel($uid, $salonId) ?? ($r->integer('personel_id') ?: null);
    }
}
