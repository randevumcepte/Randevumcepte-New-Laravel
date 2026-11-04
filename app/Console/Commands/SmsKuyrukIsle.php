<?php

namespace App\Console\Commands;

use App\Services\Sms\Gonderim\SmsKuyrukServisi;
use Illuminate\Console\Command;

/**
 * v2 SMS kuyruğu işleyicisi. Cron her dakika başlatır (flock ile tek süreç), ~55 sn 2 sn aralıkla yoklar.
 * Kurulum: docs/strangler-sms-yonetimi.md. Canlı monolite bağlı değildir.
 */
class SmsKuyrukIsle extends Command
{
    protected $signature = 'sms:kuyruk-isle {--sure=55 : saniye cinsinden calisma butcesi} {--tek : tek tur calis ve cik}';

    protected $description = 'v2 SMS Yonetimi toplu gonderim kuyrugunu isler (VoiceTelekom)';

    public function handle(SmsKuyrukServisi $kuyruk): int
    {
        $bitis = time() + max(1, (int) $this->option('sure'));
        do {
            $kuyruk->temizle();
            $vardi = $kuyruk->siradakiniIsle();
            if ($this->option('tek')) {
                break;
            }
            if (! $vardi) {
                sleep(2);
            }
        } while (time() < $bitis);

        return self::SUCCESS;
    }
}
