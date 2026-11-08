<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Musteri fotograf yukledi: personele push; v1 musteriResimEkle ('musteri' dali) ile ayni. Eski outbox isleyicisinde yoktur;
 * MusteriResimServisi bu turu yalniz bayrak acikken yazar. Bildirim satirini PushBildirimi yazar. Hata log'lanir, kalan personel
 * atlanir (v1 gibi).
 */
class MusteriResimPushIsleyici implements BildirimIsleyici
{
    public function isle(object $kayit, array $payload): void
    {
        try {
            $salonId = (int) $kayit->salon_id;
            $userId = (int) ($payload['user_id'] ?? 0);
            $ad = DB::table('users')->where('id', $userId)->value('name') ?? 'Müşteri';

            foreach (DB::table('salon_personelleri')->where('salon_id', $salonId)->pluck('id')->unique()->values() as $personelId) {
                PushBildirimi::personele((int) $personelId, $salonId)
                    ->tip(BildirimTipleri::MUSTERI_RESIM_YUKLEDI)
                    ->baslik('Yeni müşteri fotoğrafı')
                    ->govde($ad.' bir fotoğraf ekledi')
                    ->gorsel(EskiPanelLinki::url((string) ($payload['yol'] ?? '')))
                    ->deepLink('customer_detail', ['user_id' => $userId])
                    ->ekstra(['user_id' => $userId])
                    ->gonder();
            }
        } catch (\Throwable $e) {
            Log::warning('musteriResimEkle bildirim hatasi: '.$e->getMessage());
        }
    }
}
