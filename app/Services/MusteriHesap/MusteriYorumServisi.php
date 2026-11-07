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
    /** @param  bool  $markaKapsami  musteri uygulamasi: yorum markanin ilk subesine islenir, musteri baska subenin portfoyunde olabilir */
    public function kaydet(int $userId, int $salonId, int $puan, string $yorum, bool $markaKapsami = false): void
    {
        if (! $this->portfoydeMi($userId, $salonId, $markaKapsami)) {
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

    private function portfoydeMi(int $userId, int $salonId, bool $markaKapsami): bool
    {
        $bundle = $markaKapsami ? DB::table('salonlar')->where('id', $salonId)->value('app_bundle') : null;
        $salonlar = $bundle ? DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id') : [$salonId];

        return DB::table('musteri_portfoy')->whereIn('salon_id', $salonlar)->where('user_id', $userId)->exists();
    }

    /**
     * Uygunsuz yorum bildirimi (Apple 1.2 UGC, v1 yorum-bildir).
     *
     * @param  bool  $isletmeyeBildir  musteri uygulamasi: isletmenin kutusuna da satir dusurur (v1 davranisi)
     */
    public function bildir(int $userId, int $yorumId, string $sebep, bool $isletmeyeBildir = false): void
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
        if ($isletmeyeBildir) {
            $this->isletmeyeYaz($yorumId, $sebep);
        }
    }

    private function isletmeyeYaz(int $yorumId, string $sebep): void
    {
        $y = DB::table('salon_yorumlar')->where('id', $yorumId)->first(['salon_id', 'yorum']);
        $metin = trim((string) $y->yorum);
        $ozet = mb_substr($metin, 0, 60, 'UTF-8').(mb_strlen($metin, 'UTF-8') > 60 ? '...' : '');
        DB::table('bildirimler')->insert([
            'aciklama' => 'Kotuye kullanim bildirimi ('.$sebep.'): "'.$ozet.'"', 'salon_id' => $y->salon_id, 'url' => '/isletmeadmin/musteri-yorumlari',
            'tarih_saat' => now()->format('Y-m-d H:i:s'), 'okundu' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        AktiviteLog::yaz((int) $y->salon_id, null, 'yorum_bildir', 'salon_yorum', $yorumId, null, 'Müşteri yorumu bildirdi: '.$sebep, ['sebep' => $sebep]);
    }
}
