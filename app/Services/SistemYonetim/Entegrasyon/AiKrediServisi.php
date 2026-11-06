<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Exceptions\GecersizIslemException;
use App\Services\SistemYonetim\Denetim;
use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Support\JsonAyarDosyasi;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AI kredi ve kullanim (eski AiKrediController + AiKullanimLog). Anthropic kalan bakiyeyi API'den vermez:
 * kalan = elle girilen yuklenen kredi − ai_kullanim'daki tum zaman harcama. Ayar JSON'u eski monolitle ortak.
 */
class AiKrediServisi
{
    private const GIRDI_USD_M = 1.0;

    private const CIKTI_USD_M = 5.0;

    private const VARSAYILAN_KUR = 40.0;

    private const VARSAYILAN_AYAR = ['yuklenen_usd' => 0.0, 'kur' => self::VARSAYILAN_KUR, 'esik_usd' => 1.0, 'son_uyari' => null, 'guncelleme' => null];

    private const KRITIK_ORAN = 0.15;

    private const GUNLUK_LIMIT = 30;

    private const ESIK_KILIT_SN = 1800;

    private const TABLO = 'ai_kullanim';

    public const GUN_SECENEKLERI = [1 => 'Bugün', 7 => '7 Gün', 30 => '30 Gün', 0 => 'Tümü'];

    public const SALON_SIRALAMA = ['salon' => 'salon_adi', 'cagri' => 'cagri', 'cache' => 'cache_adet', 'token' => 'token', 'maliyet' => 'maliyet', 'son' => 'son'];

    private const TURLER = [
        'niyet'      => ['ad' => 'Rapor niyeti', 'renk' => '#38bdf8'],
        'sohbet'     => ['ad' => 'Sohbet', 'renk' => '#a78bfa'],
        'karne'      => ['ad' => 'Personel karnesi', 'renk' => '#f472b6'],
        'yorum'      => ['ad' => 'Personel yorumu', 'renk' => '#fbbf24'],
        'kampanya'   => ['ad' => 'Kampanya', 'renk' => '#34d399'],
        'pdf_egitim' => ['ad' => 'PDF eğitimi', 'renk' => '#fb923c'],
        'ai'         => ['ad' => 'Diğer', 'renk' => '#94a3b8'],
    ];

    public function __construct(private SistemBildirimServisi $bildirim) {}

    public function ayar(): array
    {
        return array_merge(self::VARSAYILAN_AYAR, JsonAyarDosyasi::oku($this->dosya()));
    }

    /** Kredi degisince son_uyari sifirlanir: yeniden alarm uretebilir. */
    public function ayarKaydet(float $yuklenen, ?float $kur, ?float $esik): array
    {
        $a = $this->ayar();
        $yeni = $this->yaz([
            'yuklenen_usd' => $yuklenen,
            'kur'          => ($kur ?? (float) $a['kur']) > 0 ? ($kur ?? (float) $a['kur']) : self::VARSAYILAN_KUR,
            'esik_usd'     => $esik ?? (float) $a['esik_usd'],
            'son_uyari'    => null,
        ]);
        Denetim::yaz('ai_kredi_ayar', 'ai_kredi', null, 'AI Kredi', 'Yüklenen kredi: $'.$yuklenen, ['eski' => $this->denetimOzeti($a), 'yeni' => $this->denetimOzeti($yeni)]);

        return $yeni;
    }

    public function krediEkle(float $eklenen): float
    {
        $a = $this->ayar();
        $toplam = (float) $a['yuklenen_usd'] + $eklenen;
        $this->yaz(['yuklenen_usd' => $toplam, 'son_uyari' => null]);
        Denetim::yaz('ai_kredi_ekle', 'ai_kredi', null, 'AI Kredi', 'Eklenen kredi: $'.$eklenen, ['eklenen' => $eklenen, 'yeni_toplam' => $toplam]);

        return $toplam;
    }

    public function testAlarm(): void
    {
        $a = $this->ayar();
        $r = $this->alarmGonder((float) $a['yuklenen_usd'] - $this->tumHarcama(), (float) $a['esik_usd'], (float) $a['kur']);
        Denetim::yaz('ai_kredi_test_alarm', 'ai_kredi', null, 'AI Kredi', 'Test alarmı', ['ok' => ! empty($r['ok'])]);
        if (empty($r['ok'])) {
            throw new GecersizIslemException('Alarm gönderilemedi — Sistem WhatsApp ayarını (numara + aktif) kontrol edin.');
        }
    }

    /** Ekran verisi. $gun=0 tum zaman; kalan kredi her zaman tum zaman harcamaya gore. */
    public function rapor(int $gun): array
    {
        $a = $this->ayar();
        $kur = (float) $a['kur'] > 0 ? (float) $a['kur'] : self::VARSAYILAN_KUR;
        $yuklenen = (float) $a['yuklenen_usd'];
        $tabloVar = Schema::hasTable(self::TABLO);
        $tum = $tabloVar ? $this->tumHarcama() : 0.0;
        $kalan = $yuklenen - $tum;

        return [
            'tablo_yok'       => ! $tabloVar,
            'gun'             => $gun,
            'gun_secenekleri' => array_map(fn ($g, $ad) => ['gun' => $g, 'ad' => $ad], array_keys(self::GUN_SECENEKLERI), self::GUN_SECENEKLERI),
            'ayar'            => array_intersect_key($a, self::VARSAYILAN_AYAR),
            'kur'             => $kur,
            'tum_harcama_usd' => $tum,
            'kalan_usd'       => $kalan,
            'kredi'           => [
                'girildi' => $yuklenen > 0,
                'azaldi'  => $yuklenen > 0 && $kalan < (float) $a['esik_usd'],
                'kritik'  => $yuklenen > 0 && $kalan < $yuklenen * self::KRITIK_ORAN,
            ],
            'ozet'            => $tabloVar ? $this->ozet($gun) : ['cagri' => 0, 'gercek' => 0, 'cache' => 0, 'maliyet_usd' => 0.0, 'bugun_usd' => 0.0, 'girdi' => 0, 'cikti' => 0, 'ort_usd' => 0.0, 'cache_tasarruf_usd' => 0.0],
            'turler'          => $tabloVar ? $this->turler($gun) : [],
            'gunluk'          => $tabloVar ? $this->gunluk($gun) : [],
        ];
    }

    public function salonlar(int $gun, array $l): array
    {
        if (! Schema::hasTable(self::TABLO)) {
            return ['data' => []] + ListeIstegi::meta($l['page'], $l['per_page'], 0);
        }
        $q = $this->donemli($gun)
            ->leftJoin('salonlar', 'salonlar.id', '=', self::TABLO.'.salon_id')
            ->selectRaw("ai_kullanim.salon_id, COALESCE(salonlar.salon_adi, '— (sistem)') as salon_adi, COUNT(*) as cagri,
                SUM(ai_kullanim.maliyet_usd) as maliyet, SUM(ai_kullanim.girdi_token + ai_kullanim.cikti_token) as token,
                SUM(ai_kullanim.cache) as cache_adet, MAX(ai_kullanim.created_at) as son")
            ->groupBy('ai_kullanim.salon_id', 'salonlar.salon_adi');
        if ($desen = ListeIstegi::like($l['ara'])) {
            $q->where('salonlar.salon_adi', 'like', $desen);
        }
        $toplam = DB::query()->fromSub(clone $q, 'a')->count();
        $satirlar = $q->orderBy(self::SALON_SIRALAMA[$l['sirala'] ?? ''] ?? 'maliyet', $l['yon'] === 'asc' ? 'asc' : 'desc')
            ->forPage($l['page'], $l['per_page'])->get();

        return ['data' => $satirlar->map(fn ($s) => [
            'salon_id'  => $s->salon_id ? (int) $s->salon_id : null,
            'salon_adi' => $s->salon_adi,
            'cagri'     => (int) $s->cagri,
            'cache'     => (int) $s->cache_adet,
            'token'     => (int) $s->token,
            'maliyet'   => (float) $s->maliyet,
            'son'       => $s->son,
        ])->all()] + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }

    /** AI cagrisini loglar + dusuk kredi kontrolu. Ana akisi bozmaz. */
    public function kullanimYaz(?int $salonId, string $tur, int $girdi, int $cikti, ?string $model): void
    {
        try {
            if (! Schema::hasTable(self::TABLO)) {
                return;
            }
            DB::table(self::TABLO)->insert([
                'salon_id'    => $salonId ?: null,
                'tur'         => $tur,
                'model'       => $model ? mb_substr($model, 0, 40) : null,
                'girdi_token' => $girdi,
                'cikti_token' => $cikti,
                'maliyet_usd' => $girdi / 1000000 * self::GIRDI_USD_M + $cikti / 1000000 * self::CIKTI_USD_M,
                'cache'       => 0,
                'basarili'    => 1,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            $this->esikKontrol();
        } catch (\Throwable $e) {
            Log::warning('[AI kredi] kullanim yazilamadi', ['hata' => $e->getMessage()]);
        }
    }

    /** Kalan < esik ise gunde bir alarm (son_uyari JSON'da, eski monolitle ortak). En fazla 30 dk'da bir hesaplanir. */
    private function esikKontrol(): void
    {
        if (! Cache::add('ai_esik_kontrol_kilit', 1, self::ESIK_KILIT_SN)) {
            return;
        }
        $a = $this->ayar();
        if ((float) $a['yuklenen_usd'] <= 0) {
            return;
        }
        $kalan = (float) $a['yuklenen_usd'] - $this->tumHarcama();
        if ($kalan >= (float) $a['esik_usd'] || $a['son_uyari'] === date('Y-m-d')) {
            return;
        }
        $this->alarmGonder($kalan, (float) $a['esik_usd'], (float) $a['kur']);
        $this->yaz(['son_uyari' => date('Y-m-d')]);
    }

    private function alarmGonder(float $kalan, float $esik, float $kur): array
    {
        $tl = number_format(max(0, $kalan) * $kur, 2, ',', '.');

        return $this->bildirim->gonder("⚠️ AI KREDİSİ AZALDI\n"
            .'Kalan: $'.number_format($kalan, 4, ',', '.')." (yaklaşık {$tl} ₺)\n"
            .'Eşik: $'.number_format($esik, 2, ',', '.')."\n"
            ."Patron Asistanı AI kredin bitmek üzere. Sistem Yönetimi > AI Kredi panelinden yükleme yapın.\n"
            .date('d.m.Y H:i'));
    }

    private function ozet(int $gun): array
    {
        $t = $this->donemli($gun)->selectRaw('COUNT(*) as cagri, COALESCE(SUM(CASE WHEN cache = 0 THEN 1 ELSE 0 END), 0) as gercek,
            COALESCE(SUM(cache), 0) as cache_adet, COALESCE(SUM(maliyet_usd), 0) as maliyet, COALESCE(SUM(girdi_token), 0) as girdi, COALESCE(SUM(cikti_token), 0) as cikti')->first();
        $gercek = (int) $t->gercek;
        $ort = $gercek > 0 ? (float) $t->maliyet / $gercek : 0.0;

        return [
            'cagri'              => (int) $t->cagri,
            'gercek'             => $gercek,
            'cache'              => (int) $t->cache_adet,
            'maliyet_usd'        => (float) $t->maliyet,
            'bugun_usd'          => (float) DB::table(self::TABLO)->where('created_at', '>=', date('Y-m-d 00:00:00'))->sum('maliyet_usd'),
            'girdi'              => (int) $t->girdi,
            'cikti'              => (int) $t->cikti,
            'ort_usd'            => $ort,
            'cache_tasarruf_usd' => $ort * (int) $t->cache_adet,
        ];
    }

    private function turler(int $gun): array
    {
        return $this->donemli($gun)
            ->selectRaw('tur, COUNT(*) as cagri, SUM(maliyet_usd) as maliyet, SUM(girdi_token + cikti_token) as token, SUM(cache) as cache_adet')
            ->groupBy('tur')->orderByDesc('maliyet')->get()
            ->map(fn ($t) => [
                'tur'     => $t->tur,
                'ad'      => self::TURLER[$t->tur]['ad'] ?? $t->tur,
                'renk'    => self::TURLER[$t->tur]['renk'] ?? self::TURLER['ai']['renk'],
                'cagri'   => (int) $t->cagri,
                'cache'   => (int) $t->cache_adet,
                'token'   => (int) $t->token,
                'maliyet' => (float) $t->maliyet,
            ])->all();
    }

    private function gunluk(int $gun): array
    {
        $satirlar = $this->donemli($gun)
            ->selectRaw('DATE(created_at) as gun, COUNT(*) as cagri, SUM(maliyet_usd) as maliyet')
            ->groupBy('gun')->orderByDesc('gun')->limit(self::GUNLUK_LIMIT)->get();
        $enCok = (float) $satirlar->max('maliyet');

        return $satirlar->map(fn ($g) => [
            'gun'     => $g->gun,
            'cagri'   => (int) $g->cagri,
            'maliyet' => (float) $g->maliyet,
            'oran'    => $enCok > 0 ? (int) round((float) $g->maliyet / $enCok * 100) : 0,
        ])->all();
    }

    private function donemli(int $gun)
    {
        $q = DB::table(self::TABLO);

        return $gun > 0 ? $q->where(self::TABLO.'.created_at', '>=', now()->subDays($gun - 1)->toDateString().' 00:00:00') : $q;
    }

    private function tumHarcama(): float
    {
        return (float) DB::table(self::TABLO)->sum('maliyet_usd');
    }

    private function yaz(array $yeni): array
    {
        return array_merge(self::VARSAYILAN_AYAR, JsonAyarDosyasi::guncelle($this->dosya(), $yeni + ['guncelleme' => date('Y-m-d H:i:s')]));
    }

    private function denetimOzeti(array $a): array
    {
        return ['yuklenen_usd' => $a['yuklenen_usd'], 'kur' => $a['kur'], 'esik_usd' => $a['esik_usd']];
    }

    private function dosya(): string
    {
        return (string) config('sistem.ai_ayar_dosyasi');
    }
}
