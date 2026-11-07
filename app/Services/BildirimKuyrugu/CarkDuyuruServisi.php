<?php

namespace App\Services\BildirimKuyrugu;

use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Support\SalonIdListesi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Carkifelek duyuru push'u; eski App\Services\CarkDuyuruServisi ile birebir. Hedef: carkin sube grubundaki (gecerli_salonlar,
 * yoksa yalniz bu sube) randevulu musterilerden aktif FCM token'i olanlar. {ad} ve {salon_adi} kisisellestirilir.
 */
class CarkDuyuruServisi
{
    /**
     * basarili=false: kod 404 (salon yok) ya da 422 (cark aktif degil).
     *
     * @return array{basarili:bool, gonderildi:int, hata:int, hedef:int, mesaj:?string, kod:int}
     */
    public function gonder(int $salonId, string $baslik = '', string $mesaj = ''): array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first();
        if (! $salon) {
            return $this->sonuc(false, 0, 0, 0, 'Salon bulunamadi', 404);
        }
        $cark = DB::table('carkifelek_sistemi')->where('salon_id', $salonId)->first();
        if (! $cark || ! $cark->aktifmi) {
            return $this->sonuc(false, 0, 0, 0, 'Cark sistemi aktif degil', 422);
        }

        $baslik = trim($baslik);
        $mesaj = trim($mesaj);
        if ($baslik === '') {
            $baslik = '🎡 '.$salon->salon_adi;
        } elseif (mb_substr($baslik, 0, 1) !== '🎡') {
            $baslik = '🎡 '.$baslik;
        }
        if ($mesaj === '') {
            $mesaj = (string) config('cark.duyuru.mesaj');
        }

        // Coklu sube: cark bir grup icin tanimliysa duyuru gruptaki tum subelerin randevulu musterilerine gider.
        $grup = SalonIdListesi::coz($cark->gecerli_salonlar ?? null) ?: [$salonId];

        $musteriIdleri = DB::table('randevular')->whereIn('salon_id', $grup)->whereNotNull('user_id')
            ->pluck('user_id')->unique()->filter()->values();
        if ($musteriIdleri->isEmpty()) {
            return $this->sonuc(true, 0, 0, 0, 'Bu salonda randevulu musteri bulunmuyor');
        }

        $alicilar = DB::table('bildirim_kimlikleri')->whereIn('user_id', $musteriIdleri)->where('aktif', 1)
            ->whereNotNull('bildirim_id')->where('bildirim_id', '!=', '')
            ->pluck('user_id')->unique()->values();
        if ($alicilar->isEmpty()) {
            return $this->sonuc(true, 0, 0, 0, 'Uygulamasi yuklu musteri yok');
        }

        $gonderilen = 0;
        $hatali = 0;
        foreach ($alicilar as $userId) {
            $musteri = DB::table('users')->where('id', $userId)->first(['id', 'name']);
            if (! $musteri) {
                continue;
            }
            $yer = ['{ad}' => explode(' ', (string) $musteri->name)[0] ?? '', '{salon_adi}' => (string) $salon->salon_adi];
            try {
                $s = PushBildirimi::musteriye((int) $userId, (int) $salon->id)
                    ->tip(BildirimTipleri::WHEEL_CHANCE)
                    ->baslik(strtr($baslik, $yer))
                    ->govde(strtr($mesaj, $yer))
                    ->popup(true)
                    ->deepLink('wheel', ['salon_id' => $salon->id])
                    ->ekstra(['kaynak' => 'admin_duyuru'])
                    ->gonder();
                $gonderilen += (int) ($s['sent'] ?? 0);
                $hatali += (int) ($s['failed'] ?? 0);
            } catch (\Throwable $e) {
                Log::warning("Cark duyuru push hatasi user={$userId}: ".$e->getMessage());
                $hatali++;
            }
        }

        return $this->sonuc(true, $gonderilen, $hatali, $alicilar->count(), null);
    }

    private function sonuc(bool $ok, int $gonderildi, int $hata, int $hedef, ?string $mesaj, int $kod = 200): array
    {
        return ['basarili' => $ok, 'gonderildi' => $gonderildi, 'hata' => $hata, 'hedef' => $hedef, 'mesaj' => $mesaj, 'kod' => $kod];
    }
}
