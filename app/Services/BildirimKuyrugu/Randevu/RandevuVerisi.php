<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use App\Models\Hizmetler;
use App\Models\IsletmeYetkilileri;
use App\Models\Personeller;
use App\Models\RandevuHizmetler;
use Illuminate\Support\Facades\DB;

/**
 * Randevu bildirimlerinin ortak okumalari. Eski cagri yerlerindeki sorgularla ayni; null id eskideki gibi kayit bulamaz
 * (where id is null). Eski model iliskileri (randevu->salonlar, hizmet->hizmetler) yeni modellerde olmadigi icin burada.
 */
class RandevuVerisi
{
    /** Salon satiri, tum kolonlar (WhatsApp kanali ve mesaj sablonlari icin). */
    public function salon(int|string|null $salonId): ?object
    {
        return DB::table('salonlar')->where('id', $salonId)->first();
    }

    /** Eski SalonSMSAyarlari::where(ayar_id)->where(salon_id)->value($kolon) == 1. Satir yoksa kapali. */
    public function ayarAcikMi(int|string|null $salonId, int $ayarId, string $kolon): bool
    {
        return DB::table('salon_sms_ayarlari')->where('ayar_id', $ayarId)->where('salon_id', $salonId)->value($kolon) == 1;
    }

    public function yetkiliAlani(int|string|null $yetkiliId, string $alan): mixed
    {
        return IsletmeYetkilileri::where('id', $yetkiliId)->value($alan);
    }

    /** Personelin giris hesabinin telefonu; bulunamazsa null (mesaj listeye yine girer, eskideki gibi). */
    public function personelGsm(int|string|null $personelId): mixed
    {
        return $this->yetkiliAlani(Personeller::where('id', $personelId)->value('yetkili_id'), 'gsm1');
    }

    /** Eski $hizmet->hizmetler->hizmet_adi: hizmet kaydi yoksa eskideki gibi hata firlar, outbox yeniden dener. */
    public function hizmetAdi(RandevuHizmetler $hizmet): ?string
    {
        return Hizmetler::find($hizmet->hizmet_id)->hizmet_adi;
    }

    /** Eski optional($hizmet->hizmetler)->hizmet_adi: hizmet kaydi yoksa null. */
    public function hizmetAdiVarsa(RandevuHizmetler $hizmet): ?string
    {
        return Hizmetler::where('id', $hizmet->hizmet_id)->value('hizmet_adi');
    }
}
