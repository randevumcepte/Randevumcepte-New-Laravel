<?php

namespace App\Services\Bildirim;

use App\Domain\Bildirim\CihazPlatformu;
use App\Domain\Bildirim\CihazSahibi;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;

/**
 * FCM cihaz kaydı (bildirim_kimlikleri) — eski /bildirim/cihaz-kaydet, /bildirim/cihaz-sil,
 * bildirimkimligiekleguncelle ve web bildirimCihazKaydet uçlarının TEK karşılığı.
 *
 * Kural eskiyle aynı: bir token tek satır ve tek sahiptir; aynı sahip + cihazın eski token'ları silinir.
 * (Girişte yazan Oturum\CihazKaydi çok şubeli hesapta şube başına satır açar; bu uç onları tek satıra indirir.)
 *
 * Web token'ının app_bundle'ı salonun markasından yazılır: eski ucun yazdığı tarayıcı adı ("Chrome / macOS")
 * gönderimdeki marka süzgecine takıldığı için markalı salonlarda web push hiç ulaşmıyordu.
 */
class CihazKayitServisi
{
    private const TABLO = 'bildirim_kimlikleri';

    public function kaydet(CihazSahibi $sahip, string $token, string $platform, ?string $cihaz, ?string $bundle): int
    {
        return DB::transaction(function () use ($sahip, $token, $platform, $cihaz, $bundle) {
            if ($cihaz) {
                DB::table(self::TABLO)->where($sahip->kolon, $sahip->id)->where('cihaz', $cihaz)->where('bildirim_id', '!=', $token)->delete();
            }

            $alanlar = $sahip->sahipKolonlari() + [
                'bildirim_id'       => $token,
                'cihaz'             => $cihaz,
                'app_bundle'        => $this->bundle($sahip, $platform, $bundle),
                'platform'          => $platform,
                'token_tipi'        => 'fcm',
                'kullanici_tipi'    => $sahip->kullaniciTipi,
                'salon_id'          => $sahip->salonId,
                'aktif'             => 1,
                'gonderim_hatalari' => 0,
            ];

            $mevcutId = DB::table(self::TABLO)->where('bildirim_id', $token)->orderBy('id')->value('id');
            if (! $mevcutId) {
                return ZamanliKayit::ekle(self::TABLO, $alanlar);
            }
            DB::table(self::TABLO)->where('bildirim_id', $token)->where('id', '!=', $mevcutId)->delete();
            DB::table(self::TABLO)->where('id', $mevcutId)->update($alanlar + ['updated_at' => now()]);

            return (int) $mevcutId;
        });
    }

    /** Çıkışta yalnız KENDİ token'ı pasifleşir (eski uç kimliksizdi; herkes herkesin token'ını kapatabiliyordu). */
    public function pasiflestir(CihazSahibi $sahip, string $token): int
    {
        return DB::table(self::TABLO)->where($sahip->kolon, $sahip->id)->where('bildirim_id', $token)
            ->update(['aktif' => 0, 'updated_at' => now()]);
    }

    private function bundle(CihazSahibi $sahip, string $platform, ?string $bundle): ?string
    {
        if ($platform === CihazPlatformu::WEB->value && $sahip->salonId) {
            return DB::table('salonlar')->where('id', $sahip->salonId)->value('app_bundle');
        }

        return $bundle;
    }
}
