<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Services\Rapor\MobilSatisRaporServisi;
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
 *
 * Mobil yolu (mobil=1, yalniz imzali mobil token, baslangic + bitis zorunlu): eski mobil v1 rapor hesabi ve cevabi
 * (Services/Rapor/MobilSatisRaporServisi; sekme=hizmet|urun|paket, musteri listesinde olusturan_id). Kimlik, kapsam,
 * rapor.satis (web ile ayni, 2026-11-12) ve rol 5'in yalniz kendi kaydi kurali gecerlidir; rapor.ciro_kar_gor maskesi eski mobilde yoktu.
 */
class RaporController extends RaporTabanController
{
    public function __construct(
        private RaporSecenekServisi $secenek,
        private SatisRaporServisi $satis,
        private MobilSatisRaporServisi $mobil,
    ) {}

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
        $sekme = (string) $r->input('sekme', 'tum');
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.satis');
        if ($r->boolean('mobil')) {
            [$t1, $t2] = $this->mobilAralik($r);
            $personel = $this->personel($r, $uid, $salonId);

            return ApiCevap::basarili(match ($sekme) {
                'hizmet' => $this->mobil->hizmetRaporu($salonId, $t1, $t2, $personel),
                'urun'   => $this->mobil->urunRaporu($salonId, $t1, $t2, $personel),
                'paket'  => $this->mobil->paketRaporu($salonId, $t1, $t2, $personel),
                default  => throw new GecersizIslemException('sekme şunlardan biri olmalı: hizmet, urun, paket'),
            });
        }
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
        if ($r->boolean('mobil')) {
            return ApiCevap::basarili($this->mobil->personelRaporu($salonId, ...$this->mobilAralik($r)));
        }

        return ApiCevap::basarili($this->satis->personeller(
            $salonId, $this->donem($r), $this->liste($r, SatisRaporServisi::PERSONEL_SIRALANABILIR, 'toplam_gelir'),
        ));
    }

    public function musteriler(Request $r, string $tur, int $kalem)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.satis');
        if ($r->boolean('mobil')) {
            if ($tur === 'seans') {
                throw new GecersizIslemException('tur şunlardan biri olmalı: hizmet, urun, paket');
            }
            [$t1, $t2] = $this->mobilAralik($r);
            $olusturan = $this->secenek->zorunluPersonel($uid, $salonId) ?? ($r->integer('olusturan_id') ?: null);

            return ApiCevap::basarili($this->mobil->musteriler($tur, $salonId, $kalem, $t1, $t2, $olusturan, $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
        }
        $maskeli = ! $this->yetkiVar($uid, $salonId, 'rapor.ciro_kar_gor');

        return ApiCevap::basarili($this->satis->musteriler(
            $salonId, $this->donem($r), $tur, $kalem, $this->personel($r, $uid, $salonId),
            $this->liste($r, $this->siralanabilir(SatisRaporServisi::MUSTERI_SIRALANABILIR, $maskeli), 'satis_tarihi'),
            $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'), $maskeli,
        ));
    }

    /** @return array{0:string,1:string} eski tarih1, tarih2 */
    private function mobilAralik(Request $r): array
    {
        $g = $this->alanlariDogrula($r, ['baslangic' => ['required', 'date_format:Y-m-d'], 'bitis' => ['required', 'date_format:Y-m-d']]);

        return [$g['baslangic'], $g['bitis']];
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
