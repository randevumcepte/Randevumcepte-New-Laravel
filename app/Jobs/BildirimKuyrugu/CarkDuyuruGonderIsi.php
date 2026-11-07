<?php

namespace App\Jobs\BildirimKuyrugu;

use App\Services\BildirimKuyrugu\CarkDuyuruServisi;
use Illuminate\Support\Facades\Log;

/** Carkifelek "Musterilere Duyur"; eski CarkDuyuruGonderJob. Gonderimi CarkDuyuruServisi yapar, hata burada yutulur. */
class CarkDuyuruGonderIsi extends KuyrukIsi
{
    public function __construct(protected int $salonId, protected string $baslik, protected string $mesaj) {}

    public function handle(CarkDuyuruServisi $servis): void
    {
        @set_time_limit(0);
        try {
            $sonuc = $servis->gonder($this->salonId, $this->baslik, $this->mesaj);
            Log::info('[CARK-DUYURU] v2', ['salon_id' => $this->salonId] + $sonuc);
        } catch (\Throwable $e) {
            Log::error('[CARK-DUYURU] hata', ['salon_id' => $this->salonId, 'hata' => $e->getMessage()]);
        }
    }
}
