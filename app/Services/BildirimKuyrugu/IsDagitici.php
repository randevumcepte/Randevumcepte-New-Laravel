<?php

namespace App\Services\BildirimKuyrugu;

use App\Jobs\BildirimKuyrugu\KuyrukIsi;
use Illuminate\Support\Facades\Bus;

/**
 * Toplu tur isi: queue.default sync ise hemen handle(), degilse 'hatirlatmalar' kuyruguna (eski isleyicideki dal).
 * Burada olusan istisna dispatch oncesidir, outbox'in yeniden denemesi mukerrer gondermez.
 */
class IsDagitici
{
    public function calistir(KuyrukIsi $is): void
    {
        if (config('queue.default') === 'sync') {
            app()->call([$is, 'handle']);

            return;
        }

        Bus::dispatch($is->onQueue((string) config('bildirim_kuyrugu.kuyruk_adi')));
    }
}
