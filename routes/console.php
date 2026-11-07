<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// v2 bildirim kuyrugu: varsayilan KAPALI (config/bildirim_kuyrugu.php). Zamanlama eskiyle ayni.
Schedule::command('v2:bildirim-isle')->withoutOverlapping()->everyMinute()
    ->when(fn () => (bool) config('bildirim_kuyrugu.isleyici_aktif'));
