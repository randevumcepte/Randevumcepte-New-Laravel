<?php

namespace App\Services\SistemYonetim\Izleme;

use App\Support\DiziSayfasi;
use App\Support\SorguSayfasi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guvenlik duvari paneli. Model deklaratif: panel iptables calistirmaz, kurali tabloya yazar; root watchdog bir sonraki
 * turda uygular (scripts/guvenlik-watchdog.sh, eski repoda). Tespit ve engelleme watchdog'dadir.
 */
class GuvenlikDuvariServisi
{
    public const ENGELLI_SIRALAMA = ['ip' => 'ip', 'tur' => 'tur', 'deger' => 'deger', 'zaman' => 'id'];

    public const KURAL_SIRALAMA = ['ip' => 'ip', 'aciklama' => 'aciklama', 'ekleyen' => 'ekleyen', 'zaman' => 'id'];

    public const OLAY_SIRALAMA = ['zaman' => 'id', 'tur' => 'tur_etiket', 'ip' => 'ip', 'deger' => 'deger', 'aksiyon' => 'aksiyon_etiket'];

    private const SON_OLAY_SINIRI = 100;

    private const HEDEF_TIPI = 'guvenlik_ip_kurali';

    public function ozet(): array
    {
        $son24 = $this->son24();
        $sonLoad = DB::table('guvenlik_olaylari')->where('tur', 'load_yuksek')->orderByDesc('id')->first();
        $sonOlay = DB::table('guvenlik_olaylari')->orderByDesc('id')->first();
        $kurallar = DB::table('guvenlik_ip_kurallari')->selectRaw('tip, COUNT(*) as adet')->groupBy('tip')->pluck('adet', 'tip');

        return [
            'watchdog' => $this->watchdog(),
            'ozet'     => [
                'engelli_aktif' => $this->engelliSorgusu()->count(),
                'son24_engel'   => DB::table('guvenlik_olaylari')->where('aksiyon', 'engellendi')->where('created_at', '>=', $son24)->count(),
                'son_load'      => $sonLoad ? ['deger' => GuvenlikSunumu::yuk($sonLoad->deger), 'zaman' => IzlemeZamani::bicim($sonLoad->created_at, 'd.m H:i')] : null,
                'son_olay'      => $sonOlay ? ['tur' => $sonOlay->tur, 'aksiyon' => $sonOlay->aksiyon, 'zaman' => IzlemeZamani::bicim($sonOlay->created_at, 'd.m H:i:s')] : null,
                'whitelist'     => (int) ($kurallar['whitelist'] ?? 0),
                'blacklist'     => (int) ($kurallar['blacklist'] ?? 0),
            ],
        ];
    }

    public function engelliler(array $liste): array
    {
        return SorguSayfasi::uret($this->engelliSorgusu(), $liste, ['ip', 'tur', 'detay'], self::ENGELLI_SIRALAMA, fn ($o) => GuvenlikSunumu::olay($o));
    }

    public function kurallar(string $tip, array $liste): array
    {
        return SorguSayfasi::uret(
            DB::table('guvenlik_ip_kurallari')->where('tip', $tip)->select(['id', 'ip', 'aciklama', 'ekleyen']),
            $liste,
            ['ip', 'aciklama', 'ekleyen'],
            self::KURAL_SIRALAMA,
            fn ($k) => ['id' => (int) $k->id, 'ip' => $k->ip, 'aciklama' => $k->aciklama, 'ekleyen' => $k->ekleyen],
        );
    }

    public function olaylar(array $liste): array
    {
        $satirlar = DB::table('guvenlik_olaylari')->orderByDesc('id')->limit(self::SON_OLAY_SINIRI)->get()
            ->map(fn ($o) => GuvenlikSunumu::olay($o))->all();

        return DiziSayfasi::sayfala($satirlar, $liste, fn (array $o) => $o['ip'].' '.$o['tur_etiket'].' '.$o['aksiyon_etiket'].' '.$o['detay'], self::OLAY_SIRALAMA);
    }

    /** Engeli kaldirmak IP'yi whitelist'e almaktir: watchdog bir sonraki turda ipset'ten siler. */
    public function engelKaldir(string $ip, SistemYoneticisi $yapan): string
    {
        $ip = $this->ipDogrula($ip);
        DB::transaction(function () use ($ip, $yapan) {
            $this->kuralYaz('guvenlik_engel_kaldir', $ip, 'whitelist', 'Panelden engel kaldırıldı', $yapan);
            DB::table('guvenlik_olaylari')->insert([
                'tur' => 'flood', 'ip' => $ip, 'aksiyon' => 'izlendi', 'detay' => 'Panelden engel kaldırıldı (whitelist)', 'bildirildi' => 1,
                'created_at' => IzlemeZamani::simdi()->format('Y-m-d H:i:s'),
            ]);
        });

        return $ip.' engeli kaldırıldı — en geç 1 dk içinde erişebilecek.';
    }

    public function whitelistEkle(string $ip, ?string $aciklama, SistemYoneticisi $yapan): string
    {
        $ip = $this->ipDogrula($ip);
        $this->kuralYaz('guvenlik_whitelist_ekle', $ip, 'whitelist', trim((string) $aciklama) ?: 'Manuel whitelist', $yapan);

        return $ip.' whitelist’e eklendi.';
    }

    public function blacklistEkle(string $ip, ?string $aciklama, SistemYoneticisi $yapan): string
    {
        $ip = $this->ipDogrula($ip);
        $this->kuralYaz('guvenlik_blacklist_ekle', $ip, 'blacklist', trim((string) $aciklama) ?: 'Manuel blacklist', $yapan);

        return $ip.' kalıcı engellendi (blacklist).';
    }

    public function kuralSil(int $id): void
    {
        $kural = DB::table('guvenlik_ip_kurallari')->where('id', $id)->first(['id', 'ip', 'tip']);
        if (! $kural) {
            throw new BulunamadiException('Kural bulunamadı');
        }
        DB::table('guvenlik_ip_kurallari')->where('id', $id)->delete();
        Denetim::yaz('guvenlik_kural_sil', self::HEDEF_TIPI, $id, $kural->ip, null, ['tip' => $kural->tip]);
    }

    /** Son 24 saatte engellenen, whitelist disi IP'lerin en son olayi. */
    private function engelliSorgusu(): Builder
    {
        $son24 = $this->son24();

        return DB::table('guvenlik_olaylari')
            ->whereIn('id', fn ($q) => $q->from('guvenlik_olaylari')->selectRaw('MAX(id)')->where('aksiyon', 'engellendi')->whereNotNull('ip')->where('created_at', '>=', $son24)->groupBy('ip'))
            ->whereNotIn('ip', fn ($q) => $q->from('guvenlik_ip_kurallari')->select('ip')->where('tip', 'whitelist'));
    }

    private function watchdog(): array
    {
        $durum = Schema::hasTable('guvenlik_durum')
            ? DB::table('guvenlik_durum')->whereIn('anahtar', ['heartbeat', 'aktif_ban'])->get()->keyBy('anahtar')
            : collect();
        $son = $durum->get('heartbeat')?->updated_at;
        $saniye = $son ? max(0, IzlemeZamani::simdi()->getTimestamp() - IzlemeZamani::oku($son)->getTimestamp()) : null;

        return GuvenlikSunumu::watchdog($son, $durum->get('aktif_ban')?->deger, $saniye);
    }

    private function kuralYaz(string $islem, string $ip, string $tip, string $aciklama, SistemYoneticisi $yapan): void
    {
        $eski = DB::table('guvenlik_ip_kurallari')->where('ip', $ip)->value('tip');
        DB::table('guvenlik_ip_kurallari')->updateOrInsert(['ip' => $ip], [
            'tip'        => $tip,
            'aciklama'   => mb_substr($aciklama, 0, 255),
            'ekleyen'    => mb_substr((string) ($yapan->name ?: $yapan->email), 0, 100),
            'created_at' => IzlemeZamani::simdi()->format('Y-m-d H:i:s'),
        ]);
        $id = (int) DB::table('guvenlik_ip_kurallari')->where('ip', $ip)->value('id');

        Denetim::yaz($islem, self::HEDEF_TIPI, $id, $ip, $aciklama, ['tip' => $tip, 'eski_tip' => $eski]);
    }

    private function ipDogrula(string $ip): string
    {
        $ip = trim($ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new GecersizIslemException('Geçersiz IP.');
        }

        return $ip;
    }

    private function son24(): string
    {
        return IzlemeZamani::simdi()->subHours(24)->format('Y-m-d H:i:s');
    }
}
