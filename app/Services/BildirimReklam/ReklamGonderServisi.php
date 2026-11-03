<?php

namespace App\Services\BildirimReklam;

use App\Domain\BildirimReklam\ReklamDurumu;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * "Push Gönder" — eski bildirim_reklam_gonder (web) + adminGonder (mobil v1) karşılığı.
 *
 * Alıcılar v2'de çözülür (ReklamHedefKitlesi — önizleme ile aynı sonuç), gönderimin kendisi
 * outbox ile ESKİ monolitin mevcut FCM koduna gider (`v2:bildirim-isle` → tür
 * 'bildirim_reklam_push' → BildirimReklamGonderJob, kod tekrarı yok). Böylece büyük salonlarda
 * istek zaman aşımına uğramaz (eski web 100+ kişide kuyruğa atıyordu, mobil v1 hep senkrondu).
 *
 * Mükerrer push koruması: aynı reklamın bekleyen / işlenen gönderimi varsa 409 (çift tık,
 * ağ tekrarı). Eski davranış korunur: gönderilen reklam yayına alınır (durum aktif).
 */
class ReklamGonderServisi
{
    public const OUTBOX_TURU = 'bildirim_reklam_push';

    public function __construct(
        private BildirimReklamSorgusu $sorgu,
        private ReklamHedefKitlesi $hedef,
    ) {}

    /** @return array{alici:int, kuyrukta:bool} */
    public function gonder(int $id, int $yetkiliId): array
    {
        return DB::transaction(function () use ($id, $yetkiliId) {
            $r = $this->sorgu->satir($id, kilitle: true);
            if (! (bool) $r->kanal_push) {
                throw new GecersizIslemException('Bu reklamda Push kanalı kapalı.');
            }
            if ($this->bekleyenVar($id)) {
                throw new CakismaException('Bu reklamın gönderimi zaten sürüyor.');
            }

            $alicilar = $this->hedef->alicilar($r);
            DB::table('bildirim_reklamlari')->where('id', $id)->update([
                'durum' => ReklamDurumu::AKTIF->value, 'push_gonderildi' => 1, 'updated_at' => now(),
            ]);
            if ($alicilar) {
                BildirimKuyrugu::yaz((int) $r->salon_id, self::OUTBOX_TURU, null, $yetkiliId, ['reklam_id' => $id, 'user_ids' => $alicilar]);
            }

            $hedefMetin = $r->hedef_kitle === 'segment' ? 'segment' : 'tüm müşteriler';
            AktiviteLog::yaz((int) $r->salon_id, $yetkiliId, 'bildirim_reklam_gonder', 'bildirim_reklam', $id, (string) $r->baslik,
                $alicilar ? count($alicilar).' kişiye push kuyruğa alındı (hedef: '.$hedefMetin.')' : 'Alıcı bulunamadı (hedef: '.$hedefMetin.')');

            return ['alici' => count($alicilar), 'kuyrukta' => (bool) $alicilar];
        });
    }

    private function bekleyenVar(int $reklamId): bool
    {
        return DB::table('v2_bildirim_kuyrugu')->where('tur', self::OUTBOX_TURU)->whereIn('durum', [0, 3])
            ->where('payload', 'like', '{"reklam_id":'.$reklamId.',%')->exists();
    }

    /** Kullanıcıya gösterilecek sonuç metni (web + Flutter aynı metni gösterir). */
    public static function mesaj(array $sonuc): string
    {
        return $sonuc['alici'] > 0
            ? $sonuc['alici'].' kişiye gönderim başlatıldı. Birkaç dakika içinde tamamlanır.'
            : 'Alıcı bulunamadı. Hedefteki kişilerin uygulamaya giriş yapıp bildirime izin vermiş olması gerekir (FCM token yoksa push gidemez).';
    }
}
