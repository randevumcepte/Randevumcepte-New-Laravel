<?php

namespace App\Services\MusteriHesap;

use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Musteri yorumu + puani. v1 yorumyap kuralini tek yol yapar: kullanici+salon basina tek kayit,
 * puan 1-5. Web /yorumyap sinirsiz ekliyor, puani sinirlamiyor ve salon_puanlar'daki
 * sadakat satirini ezebiliyordu (tablo carkifelek puanini da tutar).
 */
class MusteriYorumServisi
{
    public function kaydet(int $userId, int $salonId, int $puan, string $yorum): void
    {
        if (! DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->exists()) {
            throw new GecersizIslemException('Yorum yapabilmek için bu işletmenin müşterisi olmalısınız.');
        }
        $puan = max(1, min(5, $puan));

        DB::transaction(function () use ($userId, $salonId, $puan, $yorum) {
            $anahtar = ['salon_id' => $salonId, 'user_id' => $userId];
            DB::table('salon_yorumlar')->where($anahtar)->exists()
                ? DB::table('salon_yorumlar')->where($anahtar)->update(['yorum' => $yorum, 'updated_at' => now()])
                : DB::table('salon_yorumlar')->insert($anahtar + ['yorum' => $yorum, 'created_at' => now(), 'updated_at' => now()]);

            $satir = DB::table('salon_puanlar')->where($anahtar)->whereBetween('puan', [1, 5])->value('id');
            $satir
                ? DB::table('salon_puanlar')->where('id', $satir)->update(['puan' => $puan, 'updated_at' => now()])
                : DB::table('salon_puanlar')->insert($anahtar + ['puan' => $puan, 'created_at' => now(), 'updated_at' => now()]);
        });

        AktiviteLog::yaz($salonId, null, 'yorum_yap', 'salon_yorum', $userId, null, 'Müşteri salona yorum ve puan verdi', ['puan' => $puan]);
    }

    /** Uygunsuz yorum bildirimi (Apple 1.2 UGC, v1 yorum-bildir). */
    public function bildir(int $userId, int $yorumId, string $sebep): void
    {
        $guncellenen = DB::table('salon_yorumlar')->where('id', $yorumId)->update([
            'bildirilen_sayisi' => DB::raw('COALESCE(bildirilen_sayisi,0)+1'),
            'bildirim_sebep'    => mb_substr($sebep, 0, 200),
            'bildiren_id'       => $userId,
            'bildirim_tarihi'   => now(),
        ]);
        if (! $guncellenen) {
            throw new BulunamadiException('Yorum bulunamadı');
        }
    }
}
