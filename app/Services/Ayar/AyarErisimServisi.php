<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Exceptions\YetkisizErisimException;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;

/**
 * Ayarlar ekrani erisimi — sekme gorunurlugu (eski StoreAdminController@ayarlar sekme →
 * yetki haritasi) ve tema yazma kurali TEK yerde. Sekme listesi istemciye buradan gider;
 * web/Flutter hangi sekmenin acik oldugunu kendisi hesaplamaz.
 */
class AyarErisimServisi
{
    /** Tema (Görünüm) sunucuya yazabilen roller — Flutter canEditServer (rol < 4) ile ayni. */
    private const TEMA_YAZAN_ROLLER = [1, 2, 3];

    public function __construct(
        private YetkiServisi $yetki,
        private KapsamServisi $kapsam,
    ) {}

    /** GET /ayar/ozet verisi (kapsam controller'da). Hicbir sekmeye yetkisi yoksa 403 (eski yetkisizerisim). */
    public function ozet(int $yetkiliId, int $salonId): array
    {
        $sekmeler = [];
        foreach (AyarSozluk::SEKMELER as $kod => $s) {
            if (! $this->sekmeAcik($yetkiliId, $salonId, $s['yetki'])) {
                continue;
            }
            $sekmeler[] = [
                'kod'      => $kod,
                'ad'       => $s['ad'],
                'ikon'     => $s['ikon'],
                'v2'       => $s['v2'],
                'eski_url' => EskiPanelLinki::url('isletmeyonetim/ayarlar', ['p' => $s['eski_p'], 'sube' => $salonId]),
            ];
        }
        if (! array_filter($sekmeler, fn ($s) => $s['kod'] !== 'entegrasyonlar')) {
            throw new YetkisizErisimException('Ayarlar ekranına erişim yetkiniz yok');
        }

        $salon = DB::table('salonlar')->where('id', $salonId)->first(['salon_adi', 'demo_hesabi']);

        return [
            'salon_adi'           => (string) ($salon->salon_adi ?? ''),
            'demo_hesabi'         => (int) ($salon->demo_hesabi ?? 0) === 1,
            'tema_duzenleyebilir' => $this->temaYazabilir($yetkiliId, $salonId),
            'sekmeler'            => $sekmeler,
        ];
    }

    public function temaYazabilir(int $yetkiliId, int $salonId): bool
    {
        return (bool) array_intersect(self::TEMA_YAZAN_ROLLER, $this->kapsam->salonRolleri($yetkiliId, $salonId));
    }

    /** Kapsam controller'da; burada yalniz rol kurali. */
    public function temaYazmaDenetle(int $yetkiliId, int $salonId): void
    {
        if (! $this->temaYazabilir($yetkiliId, $salonId)) {
            throw new YetkisizErisimException('Tema yalnızca hesap sahibi / yönetici tarafından değiştirilebilir');
        }
    }

    /** Yetki listesi bos sekme (Entegrasyonlar) salonun her uyesine acik — eskiyle ayni. */
    private function sekmeAcik(int $yetkiliId, int $salonId, array $anahtarlar): bool
    {
        if (! $anahtarlar) {
            return true;
        }
        foreach ($anahtarlar as $a) {
            if ($this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, $a)) {
                return true;
            }
        }

        return false;
    }
}
