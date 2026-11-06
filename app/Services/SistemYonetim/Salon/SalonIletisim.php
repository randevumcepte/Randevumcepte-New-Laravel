<?php

namespace App\Services\SistemYonetim\Salon;

use Illuminate\Support\Facades\DB;

/** Salonun ulasilacak kisisi: salondaki yetkili alanlari, bossa hesap sahibi (salon_personelleri role_id=1). */
class SalonIletisim
{
    private const ROL_HESAP_SAHIBI = 1;

    /** @return array<int,object> salon id => o salonun ilk hesap sahibi */
    public function hesapSahipleri(array $salonIdler): array
    {
        if (! $salonIdler) {
            return [];
        }
        $sahipler = [];
        $satirlar = DB::table('salon_personelleri')->where('role_id', self::ROL_HESAP_SAHIBI)->whereIn('salon_id', $salonIdler)
            ->orderBy('id')->get(['salon_id', 'personel_adi', 'cep_telefon']);
        foreach ($satirlar as $s) {
            $sahipler[(int) $s->salon_id] ??= $s;
        }

        return $sahipler;
    }

    /** Ad ve telefon ikisi de bossa hesap sahibine duser. @return array{ad:string,telefon:string} */
    public function kisi(object $salon, ?object $sahip): array
    {
        $ad = trim((string) $salon->yetkili_adi);
        $telefon = trim((string) $salon->yetkili_telefon);
        if ($ad === '' && $telefon === '' && $sahip) {
            $ad = trim((string) $sahip->personel_adi);
            $telefon = trim((string) $sahip->cep_telefon);
        }

        return ['ad' => $ad, 'telefon' => $telefon];
    }

    /** Bildirim numarasi: yetkili telefonu, yoksa hesap sahibi, en son isletme telefonu. */
    public function bildirimTelefonu(object $salon): string
    {
        $telefon = trim((string) $salon->yetkili_telefon);
        if ($telefon === '') {
            $sahip = $this->hesapSahipleri([(int) $salon->id])[(int) $salon->id] ?? null;
            $telefon = trim((string) ($sahip->cep_telefon ?? ''));
        }

        return $telefon !== '' ? $telefon : trim((string) $salon->telefon_1);
    }
}
