<?php

namespace App\Console\Commands;

use App\Services\Randevu\RandevuDurumServisi;
use App\Exceptions\ApiException;
use Illuminate\Console\Command;

/**
 * DRY-RUN — v2 randevu durum isleminin PLANINI gosterir (HICBIR SEY YAZMAZ).
 *
 * Gercek bir randevu uzerinde geldi/gelmedi/kaldir/iptal/onayla'nin hangi
 * APS satirlarini guncelleyecegini/silecegini, dakika paketi dusum/iadesini ve
 * donus kodunu (hatali 2/3/4, seansDusmeOnayi) gosterir. Yazma acilmadan once
 * eski sistemin ayni randevuda yapacagi ile kiyaslamak icin.
 *
 * Kullanim:
 *   php artisan randevu:durum-plan 123456 geldi
 *   php artisan randevu:durum-plan 123456 geldi --secilen=11,12 --dogrulama=1234
 *   php artisan randevu:durum-plan 123456 gelmedi --seans-dusumu=1
 */
class RandevuDurumPlan extends Command
{
    protected $signature = 'randevu:durum-plan {randevu : randevu id}
        {islem : geldi|gelmedi|kaldir|seans-telafisi|iptal|onayla}
        {--secilen= : geldi icin secilen seans id listesi (virgullu)}
        {--seans-secim : geldi icin yeni istemci seans secim destegi}
        {--dogrulama= : geldi dogrulama kodu}
        {--kvkk= : geldi KVKK onay kodu}
        {--seans-dusumu= : gelmedi icin 1/0 (bos = sor)}';

    protected $description = 'v2 randevu durum isleminin DRY-RUN planini goster (salt okuma)';

    public function handle(RandevuDurumServisi $servis): int
    {
        $id = (int) $this->argument('randevu');
        $islem = $this->argument('islem');

        try {
            $sonuc = $this->planla($servis, $id, $islem);
        } catch (ApiException $e) {
            // Is kurali ihlali (orn. paket randevusu degil) — hata degil, sonuc.
            $this->warn("Islem yapilamaz ({$e->durumKodu}): ".$e->getMessage());

            return self::SUCCESS;
        }

        if ($sonuc === null) {
            $this->error('Gecersiz islem: geldi|gelmedi|kaldir|seans-telafisi|iptal|onayla');

            return self::FAILURE;
        }

        $this->line(json_encode($sonuc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function planla(RandevuDurumServisi $servis, int $id, string $islem): ?array
    {
        return match ($islem) {
            'geldi' => $servis->geldi($id, [
                'dogrulama_kodu'      => $this->option('dogrulama'),
                'kvkk_kodu'           => $this->option('kvkk'),
                'seans_secim_destek'  => (bool) $this->option('seans-secim'),
                'secilen_seans_idler' => $this->option('secilen') !== null
                    ? array_values(array_filter(array_map('intval', explode(',', $this->option('secilen')))))
                    : null,
            ], null, false),
            'gelmedi' => $servis->gelmedi($id, $this->option('seans-dusumu') === null ? null : (bool) (int) $this->option('seans-dusumu'), null, false),
            'kaldir'  => $servis->isaretKaldir($id, null, false),
            'seans-telafisi' => $servis->seansTelafisi($id, null, false),
            'iptal'   => $servis->iptal($id, null, false),
            'onayla'  => $servis->onayla($id, null, false),
            default   => null,
        };
    }
}
