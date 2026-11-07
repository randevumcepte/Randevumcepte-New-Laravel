<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use Illuminate\Support\Facades\DB;

/** Uygulama ici bildirim satiri; eski ApiController::bildirimekle (StoreAdminController'daki ayni govde) karsiligi. */
class UygulamaIciBildirim
{
    public function ekle(int|string|null $salonId, string $mesaj, ?string $url, int|string|null $personelId, int|string|null $musteriId, ?string $imgUrl, int|string|null $randevuId): void
    {
        $simdi = now();
        DB::table('bildirimler')->insert([
            'aciklama'    => $mesaj,
            'salon_id'    => $salonId,
            'personel_id' => $personelId,
            'url'         => $url,
            'tarih_saat'  => date('Y-m-d H:i:s'),
            'okundu'      => 0,
            'user_id'     => $musteriId,
            'img_src'     => $imgUrl,
            'randevu_id'  => $randevuId,
            'created_at'  => $simdi,
            'updated_at'  => $simdi,
        ]);
    }
}
