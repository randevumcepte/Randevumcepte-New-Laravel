<?php

namespace App\Jobs\BildirimKuyrugu;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cok alicili outbox turlerinin job tabani; eski BildirimReklamGonderJob, CarkDuyuruGonderJob, SmsTopluGonderJob
 * kurallari. Binlerce aliciyi komut dongusunde gondermek kuyrugu dakikalarca kilitler ve 5 dk geri alimi mukerrer
 * gonderim dogurur. tries=1: tekrar deneme = mukerrer ucretli mesaj; alt sinif hatayi eskisi gibi handle() icinde yutar.
 *
 * Alt sinif `handle()` yazar (eskisiyle ayni govde; bagimlilik parametreden gelebilir), `@set_time_limit(0)` ile baslar
 * ve isleyiciden IsDagitici::calistir($is) ile cagrilir. Kuyruk adini IsDagitici koyar. Sinif app/Jobs/BildirimKuyrugu/ altinda.
 */
abstract class KuyrukIsi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1700;

    public $tries = 1;
}
