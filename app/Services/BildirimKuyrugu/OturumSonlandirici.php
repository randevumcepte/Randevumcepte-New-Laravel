<?php

namespace App\Services\BildirimKuyrugu;

use App\Models\Personeller;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\Oturum\ErisimTokenServisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bir hesabin oturumlarini sonlandirma; eski App\Services\OturumServisi ile ayni adimlar ve ayni hata yutma: token iptali,
 * web oturumu damgasi, force_logout push'u. Push ulasmasa da token iptali kaldigi icin oturum sonlanir.
 *
 * Hedefler: isletmeyetkilileri.id token ve damgada, salon_personelleri.id push'ta (bildirim_kimlikleri.isletme_yetkili_id).
 *
 * Bilerek ayrilan tek nokta: token iptali yeni appin ErisimTokenServisi::digerleriniIptal metoduyla yapilir; 'sistemYonetim'
 * token'lari kapsam disidir. Eski kod ayni user_id'li sistem yoneticisi token'ini da dusururdu.
 */
class OturumSonlandirici
{
    public function __construct(private ErisimTokenServisi $token) {}

    /** Eski tokenlariIptalEt: $haricTokenId (islemi yapan oturum) korunur. */
    public function tokenlariIptalEt(int $yetkiliId, ?string $haricTokenId = null): void
    {
        try {
            $this->token->digerleriniIptal($yetkiliId, $haricTokenId);
        } catch (\Throwable $e) {
            Log::warning('OturumServisi tokenlariIptalEt hata: '.$e->getMessage());
        }
    }

    /** Eski webOturumlariniGecersizKil: eski panelin AuthenticateSession damgasi; kolon yoksa sessizce gecer. */
    public function webOturumlariniGecersizKil(int $yetkiliId): void
    {
        try {
            DB::table('isletmeyetkilileri')->where('id', $yetkiliId)
                ->update(['oturum_iptal_tarihi' => date('Y-m-d H:i:s'), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('OturumServisi webOturumlariniGecersizKil hata: '.$e->getMessage());
        }
    }

    public function forceLogoutPush(int $personelId, ?int $salonId, string $tip, string $baslik, string $govde): void
    {
        try {
            PushBildirimi::personele($personelId, $salonId)
                ->tip($tip)
                ->baslik($baslik)
                ->govde($govde)
                ->ekstra(['force_logout' => '1'])
                ->gonder();
        } catch (\Throwable $e) {
            Log::warning('OturumServisi forceLogoutPush hata: '.$e->getMessage());
        }
    }

    /** Kimligin tum salon personel satirlarina force_logout. */
    public function yetkiliTumCihazlaraForceLogout(int $yetkiliId, string $tip, string $baslik, string $govde): void
    {
        try {
            foreach (Personeller::where('yetkili_id', $yetkiliId)->get(['id', 'salon_id']) as $satir) {
                $this->forceLogoutPush((int) $satir->id, $satir->salon_id !== null ? (int) $satir->salon_id : null, $tip, $baslik, $govde);
            }
        } catch (\Throwable $e) {
            Log::warning('OturumServisi yetkiliTumCihazlaraForceLogout hata: '.$e->getMessage());
        }
    }

    /**
     * Eski personelErisimiKalktiysaSonlandir: pasife alinan personelin kimliginin ayni markada (app_bundle) baska aktif satiri
     * kalmadiysa token iptali + web damgasi + push; kaldiysa dokunulmaz. Salonun markasi yoksa kontrol tum salonlarda yapilir.
     * Korunan hesaplar hic sonlandirilmaz (eski Personeller::SISTEM_YETKILI_IDLER).
     *
     * @return bool oturum sonlandirildi mi
     */
    public function personelErisimiKalktiysaSonlandir(int $personelId, ?int $yetkiliId, ?int $salonId, string $tip, string $baslik, string $govde): bool
    {
        if (! $yetkiliId || in_array($yetkiliId, (array) config('oturum.korunan_hesaplar.isletme'), true)) {
            return false;
        }

        $markaSalonlari = null;
        if ($salonId) {
            $bundle = DB::table('salonlar')->where('id', $salonId)->value('app_bundle');
            if (! empty($bundle)) {
                $markaSalonlari = DB::table('salonlar')->where('app_bundle', $bundle)->pluck('id')->all();
            }
        }

        $sorgu = Personeller::where('yetkili_id', $yetkiliId)->where('aktif', 1);
        if (! empty($markaSalonlari)) {
            $sorgu->whereIn('salon_id', $markaSalonlari);
        }
        if ($sorgu->exists()) {
            return false;
        }

        if (DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->exists()) {
            $this->tokenlariIptalEt($yetkiliId);
            $this->webOturumlariniGecersizKil($yetkiliId);
        }
        $this->forceLogoutPush($personelId, $salonId, $tip, $baslik, $govde);

        return true;
    }
}
