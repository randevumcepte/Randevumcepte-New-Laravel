<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Jobs\BildirimKuyrugu\BildirimReklamGonderIsi;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\IsDagitici;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Bildirim reklami "Push Gonder". Reklam yoksa eskideki gibi log + tamamlandi. */
class BildirimReklamPushIsleyici implements BildirimIsleyici
{
    public function __construct(private IsDagitici $dagitici) {}

    public function isle(object $kayit, array $payload): void
    {
        $reklamId = (int) ($payload['reklam_id'] ?? 0);
        if ($reklamId <= 0 || ! DB::table('bildirim_reklamlari')->where('id', $reklamId)->exists()) {
            Log::warning('[v2:bildirim-isle] bildirim_reklam_push: reklam yok', ['id' => $kayit->id, 'reklam_id' => $reklamId]);

            return;
        }

        $this->dagitici->calistir(new BildirimReklamGonderIsi($reklamId, (array) ($payload['user_ids'] ?? [])));
    }
}
