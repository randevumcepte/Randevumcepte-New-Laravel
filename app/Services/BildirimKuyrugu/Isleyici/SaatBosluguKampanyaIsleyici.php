<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Jobs\BildirimKuyrugu\SaatBosluguPushIsi;
use App\Services\BildirimKuyrugu\BildirimIsleyici;
use App\Services\BildirimKuyrugu\IsDagitici;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Saat boslugu kampanyasi. Eski outbox isleyicisi bu turu bilmez: randevu_id bos oldugundan 'Randevu bulunamadi: ' hatasiyla
 * 3 denemede durum=2 olur, push gitmez, onbellek dusmez. Varsayilan (saat_boslugu_push_aktif=true) v1 saatBosluguKampanyaOlustur ve
 * Iptal yan etkilerini calistirir: onbellek damgasi artar, alici varsa push gider. Iptal satirinda alici yoktur. Daha once durum=2'ye
 * dusen satirlar yeniden kuyruga alinmaz: eski kampanyaya gec push gider.
 */
class SaatBosluguKampanyaIsleyici implements BildirimIsleyici
{
    public function __construct(private IsDagitici $dagitici) {}

    public function isle(object $kayit, array $payload): void
    {
        if (! config('bildirim_kuyrugu.saat_boslugu_push_aktif')) {
            throw new RuntimeException('Randevu bulunamadi: '.$kayit->randevu_id);
        }

        $salonId = (int) $kayit->salon_id;
        $this->onbellekDusur($salonId);

        $alicilar = (array) ($payload['user_ids'] ?? []);
        if ($alicilar) {
            $this->dagitici->calistir(new SaatBosluguPushIsi($salonId, (int) ($payload['kampanya_id'] ?? 0), (string) ($payload['baslik'] ?? ''), (string) ($payload['mesaj'] ?? ''), $alicilar));
        }
    }

    /** Eski _dashKarsCacheBust: salon basi surum damgasi. Eski monolitin dosya onbellegine ulasmaz, yalniz v2 okumasi tazelenir. */
    private function onbellekDusur(int $salonId): void
    {
        try {
            $anahtar = 'dashKarsVer_'.$salonId;
            Cache::forever($anahtar, ((int) Cache::get($anahtar, 0)) + 1);
        } catch (\Throwable) {
            // Eskideki gibi yoksayilir.
        }
    }
}
