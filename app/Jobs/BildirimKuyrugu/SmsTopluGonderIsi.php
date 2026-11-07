<?php

namespace App\Jobs\BildirimKuyrugu;

use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SMS Yonetimi toplu/filtreli gonderim (durum=0 kalan 'sms_toplu' satirlari); eski SmsTopluGonderJob. yeni_sms=1 ise
 * VoiceTelekom tek paket, degilse ApiController::sms_gonder_bildirimli yolu. Eski job'un aciklamasi ikinci yolu WA-first
 * der ama kod WhatsApp kullanmaz: kod esas alinir. Tekrar deneme mukerrer ucretli SMS demektir, hata burada yutulur.
 */
class SmsTopluGonderIsi extends KuyrukIsi
{
    protected array $telefonlar;

    public function __construct(protected int $salonId, array $telefonlar, protected string $mesaj, protected int $tur, protected string $baslik)
    {
        $this->telefonlar = array_values($telefonlar);
    }

    public function handle(SmsKanali $sms): void
    {
        @set_time_limit(0);
        $log = ['salon_id' => $this->salonId, 'tur' => $this->tur, 'alici' => count($this->telefonlar)];
        try {
            $salon = DB::table('salonlar')->where('id', $this->salonId)->first();
            if (! $salon) {
                Log::warning('[SMS-TOPLU] salon yok', $log);

                return;
            }
            if ((int) $salon->yeni_sms === 1) {
                $sonuc = $sms->topluVoiceTelekom($this->telefonlar, $this->mesaj, $this->salonId, $this->baslik);
            } else {
                $mesajlar = array_map(fn ($to) => ['to' => $to, 'message' => $this->mesaj], $this->telefonlar);
                $sonuc = $sms->apiBildirimli($mesajlar, true, $this->tur, false, $this->salonId);
            }
            Log::info('[SMS-TOPLU] v2', $log + ['sonuc' => $sonuc]);
        } catch (\Throwable $e) {
            Log::error('[SMS-TOPLU] hata', $log + ['hata' => $e->getMessage()]);
        }
    }
}
