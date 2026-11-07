<?php

namespace App\Console\Commands;

use App\Services\BildirimKuyrugu\KurulumDenetimi;
use App\Services\BildirimKuyrugu\KuyrukServisi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * v2 bildirim kuyrugu isleyicisi; eski monolitin ayni adli komutunun karsiligi. routes/console.php: everyMinute +
 * withoutOverlapping; ~55 sn boyunca 2 sn aralikla yoklar, kod SMS'leri pratikte birkac saniyede gider. Tur isleyicileri
 * config/bildirim_kuyrugu.php 'harita'da. BILDIRIM_ISLEYICI_AKTIF kapaliyken hicbir satira dokunmaz.
 */
class V2BildirimIsle extends Command
{
    protected $signature = 'v2:bildirim-isle {--sure=55 : saniye cinsinden calisma butcesi} {--tek : tek tur calis ve cik} {--kontrol : yalniz kurulumu denetle, kayit isleme}';

    protected $description = 'v2 bildirim kuyrugunu isler (SMS/WhatsApp, FCM, uygulama ici bildirim, Google Takvim)';

    public function handle(KuyrukServisi $kuyruk, KurulumDenetimi $kurulum): int
    {
        $eksikler = $kurulum->eksikler();
        if ($this->option('kontrol')) {
            foreach ($kurulum->uyarilar() as $uyari) {
                $this->warn($uyari);
            }
            foreach ($eksikler as $eksik) {
                $this->error($eksik);
            }
            $this->line($eksikler ? 'Kurulum eksik: bayrak acilirsa isleyici baslamaz.' : 'Kurulum tamam.');

            return $eksikler ? self::FAILURE : self::SUCCESS;
        }
        if (! config('bildirim_kuyrugu.isleyici_aktif')) {
            $this->warn('v2 bildirim isleyicisi kapali (BILDIRIM_ISLEYICI_AKTIF). Hicbir kayit islenmedi.');

            return self::SUCCESS;
        }
        if ($eksikler) {
            Log::error('[v2:bildirim-isle] kurulum eksik, kayit islenmedi', ['eksikler' => $eksikler]);
            $this->error('Kurulum eksik, hicbir kayit islenmedi. Ayrinti: php artisan v2:bildirim-isle --kontrol');

            return self::FAILURE;
        }
        if (! $kuyruk->tabloVarMi()) {
            return self::SUCCESS;
        }

        $kuyruk->yaridaKalanlariGeriAl();

        $bitis = time() + max(1, (int) $this->option('sure'));
        $toplam = 0;

        do {
            $islenen = $kuyruk->birTur();
            $toplam += $islenen;
            if ($this->option('tek')) {
                break;
            }
            if ($islenen === 0) {
                sleep(2);
            }
        } while (time() < $bitis);

        if ($toplam > 0) {
            $this->info("v2 bildirim: $toplam kayit islendi");
        }

        return self::SUCCESS;
    }
}
