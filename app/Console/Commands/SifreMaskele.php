<?php

namespace App\Console\Commands;

use App\Services\BildirimKuyrugu\SifreMaskelemeServisi;
use Illuminate\Console\Command;

/** Mesaj kayitlarindaki acik sifreleri gizler. Saatlik son kayitlar; --gecmis bir kez tum gecmis (geri alinamaz). */
class SifreMaskele extends Command
{
    protected $signature = 'v2:sifre-maskele {--gecmis : tum gecmis kayitlar ve salon_personelleri.sifre (geri alinamaz)}';

    protected $description = 'WhatsApp ve SMS kayitlarindaki acik sifreleri maskeler';

    public function handle(SifreMaskelemeServisi $servis): int
    {
        if ($this->option('gecmis')) {
            foreach ($servis->gecmis() as $yer => $sayi) {
                $this->line("$yer: $sayi");
            }

            return self::SUCCESS;
        }
        $sayi = $servis->yakin();
        if ($sayi > 0) {
            $this->info("$sayi kayit maskelendi");
        }

        return self::SUCCESS;
    }
}
