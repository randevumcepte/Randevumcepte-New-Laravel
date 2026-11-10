<?php

namespace App\Services\Panel;

use App\Support\PanelKisayollar;
use App\Support\PanelMenu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Randevu modülü — eski panelde dağınık `$_SERVER['HTTP_HOST'] == 'randevu.randevumcepte.com.tr'`
 * koşullarının TEK kaynağı. Ayar config('panel.randevu_modulu').
 *
 * Bu host'tan girilen işletme paneli yalnız randevu odaklıdır:
 *  - Menü / kısayol: yalnız 'randevu_modulu' => true işaretli öğeler (MenuGorunurluk)
 *  - Sayfa: izinsiz v2 sayfası Randevu Takvimi'ne döner (RandevuModuluSayfaKapisi)
 *  - API: satış, tahsilat, raporlama, çağrı merkezi uçları 403 (RandevuModuluApiKapisi)
 *  - Para alanları: müşteri kartı ve randevu detayı `satis_gor` bayrağıyla gizlenir
 *  - "Geçmiş randevular görünmesin" (salonlar.gecmis_randevulari_gizle) yalnız burada uygulanır
 *
 * Karar istek host'una bağlıdır; konsol/kuyruk ve mobil (app.* host) her zaman tam paneldir.
 */
class RandevuModulu
{
    /** Geçmiş randevu payı: şimdiden bu kadar önceki randevular hâlâ görünür (eskiyle aynı: 1 saat). */
    private const GECMIS_PAY = '-1 hour';

    /** @var array<int,bool> istek başına salon_id => gecmis_randevulari_gizle */
    private array $gecmisAyar = [];

    public function aktif(): bool
    {
        $hostlar = (array) config('panel.randevu_modulu.hostlar', []);

        return $hostlar !== [] && in_array(strtolower(request()->getHost()), $hostlar, true);
    }

    /** Menü / kısayol öğesi bu modülde görünür mü? (öğe biçimi PanelMenu ile aynı) */
    public function ogeGorunur(array $oge): bool
    {
        return ! $this->aktif() || ! empty($oge['randevu_modulu']);
    }

    /** v2 sayfası açık mı? $yol: 'v2/takvim/20', '/v2/takvim?sube=20' gibi. */
    public function sayfaIzinli(string $yol): bool
    {
        if (! $this->aktif()) {
            return true;
        }
        $yol = trim((string) parse_url($yol, PHP_URL_PATH), '/');
        foreach ($this->izinliSayfalar() as $desen) {
            if ($yol === $desen || Str::is([$desen, $desen.'/*'], $yol)) {
                return true;
            }
        }

        return false;
    }

    /** İşletme API ucu kapalı mı? $yol: api/v2/ sonrası. */
    public function apiKapali(string $yol): bool
    {
        $desenler = (array) config('panel.randevu_modulu.kapali_api', []);

        return $this->aktif() && $desenler !== [] && Str::is($desenler, trim($yol, '/'));
    }

    /**
     * "Geçmiş randevular görünmesin" sorgu filtresi: modül açık ve salonda ayar açıksa, şimdiden
     * 1 saat öncesinden eski randevular düşer. $q randevular tablosu üzerinde (Eloquent ya da
     * sorgu kurucu); $on tablo öneki ('r.' gibi).
     */
    public function gecmisiSuz($q, int $salonId, string $on = '')
    {
        $esik = $this->gecmisEsigi($salonId);
        if ($esik !== null) {
            $q->where($on.'tarih', '>=', substr($esik, 0, 10))
                ->whereRaw("CONCAT({$on}tarih, ' ', {$on}saat) >= ?", [$esik]);
        }

        return $q;
    }

    /** Eşik zamanı ('Y-m-d H:i:s') ya da null (filtre yok). */
    public function gecmisEsigi(int $salonId): ?string
    {
        if ($salonId <= 0 || ! $this->aktif()) {
            return null;
        }
        $this->gecmisAyar[$salonId] ??= (bool) DB::table('salonlar')->where('id', $salonId)->value('gecmis_randevulari_gizle');

        return $this->gecmisAyar[$salonId] ? date('Y-m-d H:i:s', strtotime(self::GECMIS_PAY)) : null;
    }

    /** @return string[] izinli sayfa desenleri (baştaki / yok) */
    private function izinliSayfalar(): array
    {
        $ogeler = array_merge(PanelMenu::tanim(), PanelKisayollar::kullanici());
        $yollar = [];
        foreach ($ogeler as $m) {
            if (! empty($m['randevu_modulu']) && ! empty($m['v2_rota'])) {
                $yollar[] = trim((string) parse_url($m['v2_rota'], PHP_URL_PATH), '/');
            }
        }

        return array_values(array_unique(array_merge($yollar, (array) config('panel.randevu_modulu.ek_sayfalar', []))));
    }
}
