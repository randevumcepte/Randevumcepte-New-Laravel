<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eski monolitle PAYLASILAN 'salonlar' tablosu (isletme/sube).
 *
 * Satis listesinde yalnizca OKUNUR: salon_adi (musteri panelinde bundle'a ait
 * subeyi etiketlemek icin), studyo_modu (acik/kapali'yi 'odendi' bayragiyla
 * belirlemek icin — fiyat 0 tutulur, kalan hep 0), faturasiz_gizle (fatura
 * kesilmemis adisyonlari listeden gizleme ayari), app_bundle (white-label
 * musteri panelinde markanin tum subelerini kapsamak icin). Migration YOK.
 */
class Salonlar extends Model
{
    protected $table = 'salonlar';

    protected $guarded = [];

    /** Klinik/studyo salon turleri: musteri yerine "danisan" denir (eski menu/metinlerle birebir). */
    public const DANISAN_TURLERI = [15, 28, 29];

    public static function danisanMi($salonTuruId): bool
    {
        return in_array((int) $salonTuruId, self::DANISAN_TURLERI, true);
    }

    /** Hizmet kaleminde personel zorunlu mu — stüdyo modu HARİÇ (prim yok; eski adisyon_hizmet_formu kuralı). */
    public static function hizmetPersonelZorunlu(int $salonId): bool
    {
        return ! self::studyoModu($salonId);
    }

    /** Stüdyo modu bayrağı — TEK okuma noktası (kurallar: Domain\Studyo\StudyoPolitikasi). */
    public static function studyoModu(int $salonId): bool
    {
        return (int) self::where('id', $salonId)->value('studyo_modu') === 1;
    }

    /** Grup dersi modülü açık mı. */
    public static function grupDersiAktif(int $salonId): bool
    {
        return (int) self::where('id', $salonId)->value('grup_dersi_aktif') === 1;
    }
}
