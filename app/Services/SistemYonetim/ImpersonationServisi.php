<?php

namespace App\Services\SistemYonetim;

use App\Domain\SistemYonetim\SistemRolu;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\YetkisizErisimException;
use App\Models\SistemYoneticisi;
use App\Services\Oturum\ErisimTokenServisi;
use Illuminate\Support\Facades\DB;

/**
 * "Salon hesabina gir" — eski PanelController::salonHesabinaGir karsiligi. Oturum yerine yetkiliye ait kisa
 * omurlu token uretilir: tarayicida panel token'i olur, oturum bitince sunucuda iptal edilir. Her giris loglanir.
 */
class ImpersonationServisi
{
    public function __construct(private ErisimTokenServisi $token, private SalonKapsami $kapsam) {}

    /** @param array{yetkili_id?:?int,sebep?:?string,ticket_id?:?int} $g */
    public function baslat(int $salonId, array $g, SistemYoneticisi $yonetici, string $ip, string $tarayici): array
    {
        $salon = $this->kapsam->salonuGetir($salonId, $yonetici, ['id', 'salon_adi', 'askiya_alindi']);
        if ((int) $salon->askiya_alindi === 1) {
            throw new GecersizIslemException('Salon askıda — önce aktif edin.');
        }

        $yetkili = ! empty($g['yetkili_id']) ? $this->seciliHesap($salonId, (int) $g['yetkili_id']) : $this->varsayilanHesap($salonId);
        if (! $yetkili) {
            throw new GecersizIslemException('Bu salona bağlı yetkili-personel kaydı yok. Önce salon detayından yetkili ekleyin.');
        }

        $sebep = trim((string) ($g['sebep'] ?? '')) ?: 'Destek girişi';
        $logId = DB::table('sistemyonetim_impersonation_loglari')->insertGetId([
            'user_id' => $yonetici->id, 'user_name' => $yonetici->name,
            'salon_id' => $salon->id, 'salon_adi' => $salon->salon_adi,
            'isletme_yetkili_id' => $yetkili->id, 'isletme_yetkili_email' => $yetkili->email,
            'sebep' => mb_substr($sebep, 0, 255), 'ticket_id' => $g['ticket_id'] ?? null, 'baslangic_tarihi' => now(),
            'ip' => $ip, 'user_agent' => mb_substr($tarayici, 0, 255),
        ]);

        Denetim::yaz('salon_hesabina_gir', 'salon', (int) $salon->id, $salon->salon_adi, $sebep, [
            'yetkili_id' => $yetkili->id, 'yetkili_email' => $yetkili->email,
            'hesap_tipi' => ! empty($yetkili->is_admin) ? 'sahip' : 'personel', 'ticket_id' => $g['ticket_id'] ?? null,
        ]);

        return [
            'token'     => $this->token->impersonationUret((int) $yetkili->id, $logId),
            'log_id'    => $logId,
            'salon_adi' => $salon->salon_adi,
            'yetkili'   => $yetkili->name,
            'hedef'     => '/v2/ozet?sube='.$salon->id,
        ];
    }

    /** Yalniz oturumu acan yonetici (ya da super admin) kapatabilir. Kapali log'da tekrar cagri zararsiz. */
    public function bitir(int $logId, SistemYoneticisi $yonetici): void
    {
        $log = DB::table('sistemyonetim_impersonation_loglari')->where('id', $logId)->first(['id', 'user_id', 'bitis_tarihi']);
        if (! $log) {
            return;
        }
        if ((int) $log->user_id !== (int) $yonetici->id && $yonetici->rol() !== SistemRolu::SuperAdmin) {
            throw new YetkisizErisimException();
        }
        $this->token->impersonationIptal($logId);
        if (! $log->bitis_tarihi) {
            DB::table('sistemyonetim_impersonation_loglari')->where('id', $logId)->update(['bitis_tarihi' => now()]);
        }
    }

    /** Secilen hesap mutlaka bu salona bagli olmali. */
    private function seciliHesap(int $salonId, int $yetkiliId): object
    {
        $bagli = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->exists()
            || DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->where('salon_id', $salonId)->exists();
        $yetkili = $bagli ? DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->first(['id', 'name', 'email', 'is_admin']) : null;
        if (! $yetkili) {
            throw new GecersizIslemException('Seçilen hesap bu salona bağlı değil.');
        }

        return $yetkili;
    }

    /** Once salon sahibi, yoksa ilk aktif hesap; hic aktif yoksa pasif dahil; en son eski salon_id bagi. */
    private function varsayilanHesap(int $salonId): ?object
    {
        $kisiler = fn () => DB::table('salon_personelleri')->where('salon_id', $salonId)->whereNotNull('yetkili_id')->orderByRaw('(role_id = 1) desc')->orderBy('id');
        $yetkiliId = (clone $kisiler())->where('aktif', 1)->value('yetkili_id') ?? $kisiler()->value('yetkili_id');
        $kolonlar = ['id', 'name', 'email', 'is_admin'];

        return ($yetkiliId ? DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->first($kolonlar) : null)
            ?? DB::table('isletmeyetkilileri')->where('salon_id', $salonId)->first($kolonlar);
    }
}
