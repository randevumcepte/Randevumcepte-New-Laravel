<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\SaatBosluguKampanyasi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Saat boşluğu kampanyası oluşturma ve iptal. Eski saatBosluguKampanyaOlustur ve
 * saatBosluguKampanyaIptal ile aynı kurallar ve aynı metinler.
 *
 * Yan etki outbox'tadır: monolitin `v2:bildirim-isle` komutu push'u mevcut NotificationService ile
 * gönderir ve dashboard önbelleğini (`dashKarsVer_{salon}`) düşürür. Alıcılar burada çözülür.
 */
class SaatBosluguKampanyaYazServisi
{
    public const OUTBOX_TURU = 'saat_boslugu_kampanya';

    private const PARCA = 2000;

    /** @param array{anahtar:string, ad:string, bas:int, bit:int, indirim:int} $g */
    public function olustur(int $salonId, array $g, int $yetkiliId): array
    {
        if (! SaatBosluguKampanyasi::gecerliMi($g['anahtar'])) {
            throw new GecersizIslemException('Geçersiz boşluk türü.');
        }
        if ($g['indirim'] < 5 || $g['indirim'] > 70) {
            throw new GecersizIslemException('İndirim oranı 5-70 aralığında olmalıdır.');
        }
        if ($g['bit'] <= $g['bas']) {
            throw new GecersizIslemException('Geçersiz saat aralığı.');
        }

        $bas = now();
        $bit = now()->addDays(SaatBosluguKampanyasi::GECERLILIK_GUN)->endOfDay();

        return DB::transaction(function () use ($salonId, $g, $yetkiliId, $bas, $bit) {
            // Aynı salonda çift dokunuş iki kampanya açmasın.
            DB::table('salonlar')->where('id', $salonId)->lockForUpdate()->value('id');
            $varsa = DB::table('kampanyalar')->where('salon_id', $salonId)
                ->where('kampanya_detay', 'like', '%'.SaatBosluguKampanyasi::etiket($g['anahtar']).'%')
                ->where('kampanya_bitis_tarihi', '>=', $bas->toDateTimeString())
                ->value('id');
            if ($varsa) {
                return ['status' => 'duplicate', 'kampanya_id' => (int) $varsa, 'mesaj' => $g['ad'].' için aktif bir kampanya zaten var.'];
            }

            $baslik = SaatBosluguKampanyasi::baslik($g['ad'], $g['indirim']);
            $aciklama = SaatBosluguKampanyasi::aciklama($g['ad'], $g['bas'], $g['bit'], $g['indirim']);
            $id = (int) DB::table('kampanyalar')->insertGetId([
                'salon_id'                  => $salonId,
                'kampanya_baslik'           => $baslik,
                'kampanya_aciklama'         => $aciklama,
                'kampanya_baslangic_tarihi' => $bas->toDateTimeString(),
                'kampanya_bitis_tarihi'     => $bit->toDateTimeString(),
                'kampanya_detay'            => SaatBosluguKampanyasi::detay($aciklama, $bas->format('d.m.Y'), $bit->format('d.m.Y'), $g['anahtar']),
                'onayli'                    => 1,
                'created_at'                => $bas,
                'updated_at'                => $bas,
            ]);

            BildirimKuyrugu::yaz($salonId, self::OUTBOX_TURU, null, $yetkiliId, [
                'kampanya_id' => $id, 'baslik' => $baslik, 'mesaj' => $aciklama, 'user_ids' => $this->pushAlicilari($salonId),
            ]);
            AktiviteLog::yaz($salonId, $yetkiliId, 'kampanya_ekle', 'kampanya', $id, $baslik, 'Saat boslugu indirim kampanyasi olusturuldu.');

            return [
                'status'      => 'success',
                'kampanya_id' => $id,
                'baslik'      => $baslik,
                'bitis'       => $bit->toDateTimeString(),
                'mesaj'       => $g['ad'].' için %'.$g['indirim'].' indirimli kampanya oluşturuldu.',
            ];
        });
    }

    public function iptal(int $salonId, int $id, int $yetkiliId): void
    {
        $k = DB::table('kampanyalar')->where('id', $id)->where('salon_id', $salonId)->first(['id', 'kampanya_baslik', 'kampanya_detay'])
            ?? throw new BulunamadiException('Kampanya bulunamadı.');
        if (! SaatBosluguKampanyasi::etiketliMi($k->kampanya_detay)) {
            throw new GecersizIslemException('Bu kampanya panel üzerinden iptal edilemez.');
        }

        DB::transaction(function () use ($salonId, $id, $yetkiliId) {
            DB::table('kampanyalar')->where('id', $id)->update([
                'onayli' => 0, 'kampanya_bitis_tarihi' => now()->subSecond()->toDateTimeString(), 'updated_at' => now(),
            ]);
            // Alıcısız kayıt: yalnız dashboard önbelleği düşer, push gitmez.
            BildirimKuyrugu::yaz($salonId, self::OUTBOX_TURU, null, $yetkiliId, ['kampanya_id' => $id, 'user_ids' => []]);
        });
        AktiviteLog::yaz($salonId, $yetkiliId, 'kampanya_iptal', 'kampanya', $id, (string) $k->kampanya_baslik, 'Saat boslugu kampanyasi iptal edildi.');
    }

    /** @return list<int> Aktif, kara listede olmayan ve aktif cihaz kaydı bulunan müşteriler */
    private function pushAlicilari(int $salonId): array
    {
        $portfoy = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('aktif', 1)
            ->where(fn ($w) => $w->whereNull('kara_liste')->orWhere('kara_liste', '!=', 1))
            ->distinct()->pluck('user_id')->filter()->all();

        $alicilar = [];
        foreach (array_chunk($portfoy, self::PARCA) as $parca) {
            array_push($alicilar, ...DB::table('bildirim_kimlikleri')->whereIn('user_id', $parca)->where('aktif', 1)
                ->whereNotNull('bildirim_id')->where('bildirim_id', '!=', '')
                ->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($alicilar));
    }
}
