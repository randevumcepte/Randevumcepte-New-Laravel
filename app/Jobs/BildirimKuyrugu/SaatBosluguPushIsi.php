<?php

namespace App\Jobs\BildirimKuyrugu;

use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use Illuminate\Support\Facades\Log;

/**
 * Saat boslugu kampanyasi push'u; v1 ApiController::_gapKampanyaPushGonder dongusu (v1'de istek sonrasi calisirdi).
 * Alicilar v2'de v1 ile ayni kuralla cozulur (SaatBosluguKampanyaYazServisi). Eski outbox isleyicisinde karsiligi yoktur.
 */
class SaatBosluguPushIsi extends KuyrukIsi
{
    public function __construct(
        protected int $salonId,
        protected int $kampanyaId,
        protected string $baslik,
        protected string $mesaj,
        protected array $userIds,
    ) {}

    public function handle(): void
    {
        @set_time_limit(0);

        foreach ($this->userIds as $uid) {
            try {
                PushBildirimi::musteriye((int) $uid, $this->salonId)
                    ->tip(BildirimTipleri::DISCOUNT)
                    ->baslik($this->baslik)
                    ->govde($this->mesaj)
                    ->popup(true)
                    ->ekstra(['kaynak' => 'gap_kampanya', 'kampanya_id' => $this->kampanyaId])
                    ->gonder();
            } catch (\Throwable $e) {
                Log::warning('[GAP-KAMPANYA] push fail user='.$uid.': '.$e->getMessage());
            }
        }
    }
}
