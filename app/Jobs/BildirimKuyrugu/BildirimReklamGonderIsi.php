<?php

namespace App\Jobs\BildirimKuyrugu;

use App\Services\BildirimKuyrugu\Kanal\BildirimTipleri;
use App\Services\BildirimKuyrugu\Kanal\PushBildirimi;
use App\Services\BildirimReklam\BildirimReklamSunumu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bildirim reklamini verilen alicilara push olarak gonderir; eski BildirimReklamGonderJob. Alicilar v2'de cozulur
 * (ReklamHedefKitlesi); eski job'un kendi hedef cozumu yalniz eski panel yolunda calisirdi, outbox'tan hic girilmezdi.
 * Gorsel adresi eski panelden: dosya eski monolitin public dizininde (eskide url(), yani eski APP_URL).
 */
class BildirimReklamGonderIsi extends KuyrukIsi
{
    /** @var list<int> */
    protected array $userIds;

    public function __construct(protected int $reklamId, array $userIds)
    {
        $this->userIds = array_values(array_unique(array_map('intval', $userIds)));
    }

    public function handle(): int
    {
        @set_time_limit(0);

        $reklam = DB::table('bildirim_reklamlari')->where('id', $this->reklamId)->first();
        if (! $reklam || ! $reklam->kanal_push) {
            return 0;
        }
        if (empty($this->userIds)) {
            Log::info('[REKLAM-PUSH] hedef bos', ['reklam_id' => $this->reklamId]);

            return 0;
        }

        $gorselUrl = BildirimReklamSunumu::gorselUrl($reklam->gorsel);
        $gonderilen = 0;
        foreach ($this->userIds as $uid) {
            try {
                $push = PushBildirimi::musteriye($uid, (int) $reklam->salon_id)
                    ->tip(BildirimTipleri::DISCOUNT)
                    ->baslik($reklam->baslik)
                    ->govde((string) ($reklam->mesaj ?: ''))
                    ->deepLink('reklam_detay', ['reklam_id' => $reklam->id]);
                if ($gorselUrl) {
                    $push->gorsel($gorselUrl);
                }
                $push->gonder();
                $gonderilen++;
            } catch (\Throwable) {
                // Tek musteri hatasi tum gonderimi bozmasin (retry yok)
            }
        }

        Log::info('[REKLAM-PUSH] tamamlandi', [
            'reklam_id'  => $this->reklamId,
            'hedef'      => count($this->userIds),
            'gonderilen' => $gonderilen,
        ]);

        return $gonderilen;
    }
}
