<?php

namespace App\Services\SistemYonetim\Izleme;

use App\Support\SorguSayfasi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Panel giris denemeleri ve "salon hesabina gir" kayitlari. */
class GuvenlikLogServisi
{
    public const GIRIS_SIRALAMA = ['zaman' => 'id', 'email' => 'email_attempt', 'durum' => 'basarili', 'ip' => 'ip'];

    public const IMPERSONATION_SIRALAMA = ['personel' => 'user_name', 'salon' => 'salon_adi', 'baslangic' => 'id', 'ip' => 'ip'];

    public function girisler(array $liste): array
    {
        return SorguSayfasi::uret(
            DB::table('sistemyonetim_login_loglari')->select(IzlemeSunumu::GIRIS_KOLONLARI),
            $liste,
            ['email_attempt', 'hata', 'ip', 'user_agent'],
            self::GIRIS_SIRALAMA,
            fn ($k) => IzlemeSunumu::giris($k),
        );
    }

    public function impersonation(array $liste): array
    {
        return SorguSayfasi::uret(
            DB::table('sistemyonetim_impersonation_loglari')
                ->select(['id', 'user_name', 'salon_id', 'salon_adi', 'isletme_yetkili_email', 'sebep', 'ticket_id', 'baslangic_tarihi', 'bitis_tarihi', 'ip']),
            $liste,
            ['user_name', 'salon_adi', 'isletme_yetkili_email', 'sebep', 'ip'],
            self::IMPERSONATION_SIRALAMA,
            fn ($k) => $this->impersonationSatiri($k),
        );
    }

    private function impersonationSatiri(object $k): array
    {
        return [
            'id'            => (int) $k->id,
            'personel'      => $k->user_name,
            'salon_id'      => (int) $k->salon_id,
            'salon_adi'     => $k->salon_adi,
            'yetkili_email' => $k->isletme_yetkili_email,
            'sebep'         => $k->sebep,
            'ticket_id'     => $k->ticket_id !== null ? (int) $k->ticket_id : null,
            'baslangic'     => IzlemeZamani::bicim($k->baslangic_tarihi),
            'sure'          => $k->bitis_tarihi
                ? IzlemeZamani::oku($k->baslangic_tarihi)->diffForHumans(IzlemeZamani::oku($k->bitis_tarihi), CarbonInterface::DIFF_ABSOLUTE)
                : null,
            'ip'            => $k->ip,
        ];
    }
}
