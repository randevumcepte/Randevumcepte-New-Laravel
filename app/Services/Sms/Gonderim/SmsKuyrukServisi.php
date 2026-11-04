<?php

namespace App\Services\Sms\Gonderim;

use App\Domain\Sms\SmsKuyrukDurumu;
use App\Exceptions\BulunamadiException;
use App\Services\Sms\Rapor\VoiceTelekomRapor;
use App\Services\Sms\SmsHesapServisi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v2 SMS kuyruğu: ekle, durum oku, işle. Tablo ortak (v2_bildirim_kuyrugu) ama durumlar eski
 * tüketiciden ayrı (bkz. SmsKuyrukDurumu); canlı monolite kod eklemeden çalışır.
 *
 * Tekrar deneme YOK: ücretli gönderimde belirsiz sonuç mükerrer SMS'ten iyidir. Çok bekleyen kayıt
 * (worker kapalıydı) geç gitmesin diye iptal edilir.
 */
class SmsKuyrukServisi
{
    private const TABLO = 'v2_bildirim_kuyrugu';

    private const TUR = 'sms_toplu';

    private const YARIDA_KALAN_DK = 10;

    public function __construct(
        private SmsHesapServisi $hesap,
        private VoiceTelekomGonderici $gonderici,
        private VoiceTelekomRapor $rapor,
    ) {}

    /** @param array{telefonlar:string[], mesaj:string, tur:int, baslik:string} $payload */
    public function ekle(int $salonId, int $yetkiliId, array $payload): int
    {
        return (int) DB::table(self::TABLO)->insertGetId([
            'salon_id' => $salonId, 'tur' => self::TUR, 'yetkili_id' => $yetkiliId,
            'payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'durum'    => SmsKuyrukDurumu::BEKLIYOR, 'deneme' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function durum(int $salonId, int $id): array
    {
        $k = DB::table(self::TABLO)->where('id', $id)->where('salon_id', $salonId)->where('tur', self::TUR)->first(['id', 'durum', 'hata']);
        if (! $k) {
            throw new BulunamadiException('Gönderim bulunamadı');
        }

        return ['id' => (int) $k->id, 'hata' => (int) $k->durum === SmsKuyrukDurumu::HATA ? (string) $k->hata : null] + SmsKuyrukDurumu::sunum((int) $k->durum);
    }

    /** Yarıda kalan + süresi geçen kayıtları kapatır (ikisi de yeniden gönderilmez). */
    public function temizle(): void
    {
        $this->kapat(SmsKuyrukDurumu::ISLENIYOR, self::YARIDA_KALAN_DK, 'Gönderim yarıda kaldı; mükerrer SMS olmaması için yeniden denenmedi. SMS Raporları\'nı kontrol edin.');
        $this->kapat(SmsKuyrukDurumu::BEKLIYOR, (int) config('sms.kuyruk_gecerlilik_dk'), 'Gönderim süresinde işlenemediği için iptal edildi.');
    }

    /** En eski bekleyeni atomik alıp işler. @return bool işlenecek kayıt vardı mı */
    public function siradakiniIsle(): bool
    {
        $id = DB::table(self::TABLO)->where('tur', self::TUR)->where('durum', SmsKuyrukDurumu::BEKLIYOR)->orderBy('id')->value('id');
        if (! $id) {
            return false;
        }
        $alindi = DB::table(self::TABLO)->where('id', $id)->where('durum', SmsKuyrukDurumu::BEKLIYOR)
            ->update(['durum' => SmsKuyrukDurumu::ISLENIYOR, 'deneme' => 1, 'updated_at' => now()]);
        if ($alindi) {
            $this->isle((int) $id);
        }

        return true;
    }

    private function isle(int $id): void
    {
        $k = DB::table(self::TABLO)->where('id', $id)->first(['id', 'salon_id', 'payload']);
        $p = json_decode((string) $k->payload, true) ?: [];
        try {
            $salon = $this->hesap->zorunlu((int) $k->salon_id);
            $paket = $this->gonderici->gonder($salon, (array) ($p['telefonlar'] ?? []), (string) ($p['mesaj'] ?? ''), (string) ($p['baslik'] ?? 'Toplu SMS'));
            $this->bitir($id, SmsKuyrukDurumu::GONDERILDI, null);
            $this->rapor->unut($salon);
            Log::info('[SMS-V2] gonderildi', ['id' => $id, 'salon_id' => $k->salon_id, 'alici' => count((array) ($p['telefonlar'] ?? [])), 'paket' => $paket]);
        } catch (\Throwable $e) {
            $this->bitir($id, SmsKuyrukDurumu::HATA, mb_substr($e->getMessage(), 0, 2000));
            Log::error('[SMS-V2] hata', ['id' => $id, 'salon_id' => $k->salon_id, 'hata' => $e->getMessage()]);
        }
    }

    private function bitir(int $id, int $durum, ?string $hata): void
    {
        DB::table(self::TABLO)->where('id', $id)->update(['durum' => $durum, 'hata' => $hata, 'islenme_at' => now(), 'updated_at' => now()]);
    }

    private function kapat(int $durum, int $dakika, string $neden): void
    {
        DB::table(self::TABLO)->where('tur', self::TUR)->where('durum', $durum)->where('updated_at', '<', now()->subMinutes($dakika))
            ->update(['durum' => SmsKuyrukDurumu::HATA, 'hata' => $neden, 'updated_at' => now()]);
    }
}
