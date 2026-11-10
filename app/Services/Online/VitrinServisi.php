<?php

namespace App\Services\Online;

use App\Services\MusteriHesap\MusteriCarkServisi;
use App\Support\EskiPanelLinki;
use App\Support\KvkkMaske;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isletmenin herkese acik vitrini (mini site + uygulama ana sayfasi). Eski
 * HomeController@salonDetay_anasayfa'nin + view icindeki sorgularin TEK karsiligi:
 * blade artik sorgu yazmaz, API ile ayni veriyi alir. Yalniz herkese acik alanlar donulur.
 */
class VitrinServisi
{
    private const YORUM_SAYFA = 20;
    private const ANKET_YORUM_LIMIT = 30;
    private const ANKET_MIN_NPS = 9;
    private const ANKET_MIN_CSAT = 4.5;

    /** Yanlislikla yuklenip vitrinden cikarilan gorseller (eski blade'deki liste). */
    private const GIZLI_GORSEL_PARCALARI = ['685e8dd97888b'];

    public function __construct(private OnlineSalonKapisi $kapi, private OnlineSecenekServisi $secenek, private MusteriCarkServisi $cark) {}

    /** Web host'u ya da uygulama paketi → salon(lar) + marka bilgisi (v1 salonAyarlariByBundle). */
    public function coz(?string $domain, ?string $bundle): array
    {
        $idler = $domain ? [(int) $this->kapi->domaindenSalon($domain)->id] : $this->kapi->bundleSalonlari((string) $bundle);
        $salonlar = array_map(fn ($id) => $this->marka($this->kapi->salon($id)), $idler);

        return ['salon_id' => $salonlar[0]['id'] ?? null, 'subeler' => $salonlar];
    }

    /** Uygulama acilisi (v1 salonAyarlariByBundle): herhangi bir sube aciksa randevu dugmesi gorunur. */
    public function markaOzeti(string $bundle): array
    {
        $subeler = DB::table('salonlar')->where('app_bundle', $bundle)->orderBy('id')->get(['id', 'salon_adi', 'musteri_online_randevu_aktif', 'uyelik_bitis_tarihi']);
        if ($subeler->isEmpty()) {
            return ['musteri_online_randevu_aktif' => 0, 'lisans_aktif' => 0, 'uyelik_bitis_tarihi' => null, 'salon_adi' => null];
        }
        $acik = $subeler->filter(fn ($s) => (int) ($s->musteri_online_randevu_aktif ?? 0) === 1);
        $bugun = strtotime('today');
        $lisansli = $subeler->contains(fn ($s) => ! empty($s->uyelik_bitis_tarihi) && strtotime((string) $s->uyelik_bitis_tarihi) >= $bugun);

        return [
            'musteri_online_randevu_aktif' => $acik->isNotEmpty() ? 1 : 0,
            'lisans_aktif'                 => $lisansli ? 1 : 0,
            'uyelik_bitis_tarihi'          => $subeler->filter(fn ($s) => ! empty($s->uyelik_bitis_tarihi))->max('uyelik_bitis_tarihi'),
            'salon_adi'                    => $subeler->first()->salon_adi,
            'bundle_baslik'                => Schema::hasTable('app_bundle_ayarlari') ? DB::table('app_bundle_ayarlari')->where('app_bundle', $bundle)->value('bundle_baslik') : null,
            'sube_sayisi'                  => $subeler->count(),
            'online_aktif_sube_sayisi'     => $acik->count(),
        ];
    }

    /** Magaza surumu ve linkleri (v1 versiyonAppKontrol): once marka ayari, yoksa markanin ilk subesi. */
    public function surum(string $bundle): array
    {
        $ayar = Schema::hasTable('app_bundle_ayarlari') ? DB::table('app_bundle_ayarlari')->where('app_bundle', $bundle)->first() : null;
        $kaynak = $ayar && (! empty($ayar->android_son_versiyon) || ! empty($ayar->ios_son_versiyon))
            ? $ayar
            : DB::table('salonlar')->where('app_bundle', $bundle)->orderBy('id')->first();

        return [
            'ios_latest'     => $kaynak->ios_son_versiyon ?? '',
            'android_latest' => $kaynak->android_son_versiyon ?? '',
            'huawei_latest'  => $kaynak->huawei_son_versiyon ?? '',
            'play_store'     => $kaynak->android_uygulama ?? '',
            'app_store'      => $kaynak->ios_uygulama ?? '',
            'app_gallery'    => $kaynak->huawei_uygulama ?? '',
        ];
    }

    /**
     * Markanin tum subelerinin yorumlari (v1 salonyorumlari): sayfasiz, ad "Ahmet Y." bicimi. Yorum yazarinin id'si
     * disari verilmez; `kendi` istegi yapan musterinin yorumunu gosterir. Yorum birakma markanin ilk subesine islenir.
     */
    public function markaYorumlari(string $bundle, ?int $userId): array
    {
        $salonlar = $this->kapi->bundleSalonlari($bundle);
        $dagilim = array_fill_keys(['1', '2', '3', '4', '5'], 0);
        if (! $salonlar) {
            return ['ortalama' => 0, 'toplam_puan' => 0, 'toplam_yorum' => 0, 'dagilim' => $dagilim, 'yorumlar' => [], 'salon_id' => null, 'kullanici_yorum_yapabilir' => false];
        }
        $puanlar = DB::table('salon_puanlar')->whereIn('salon_id', $salonlar)->whereBetween('puan', [1, 5])->get(['user_id', 'puan']);
        $kisiPuani = [];
        foreach ($puanlar as $p) {
            $dagilim[(string) (int) round((float) $p->puan)]++;
            $kisiPuani[$p->user_id] = (int) round((float) $p->puan);
        }
        $yorumlar = DB::table('salon_yorumlar as y')->leftJoin('users as u', 'u.id', '=', 'y.user_id')->whereIn('y.salon_id', $salonlar)
            ->orderByDesc('y.updated_at')->get(['y.id', 'y.user_id', 'y.yorum', 'y.updated_at', 'u.name']);

        return [
            'ortalama'     => $puanlar->count() ? round((float) $puanlar->avg('puan'), 1) : 0,
            'toplam_puan'  => $puanlar->count(),
            'toplam_yorum' => $yorumlar->count(),
            'dagilim'      => $dagilim,
            'yorumlar'     => $yorumlar->map(fn ($y) => [
                'id'            => (int) $y->id,
                'kendi'         => $userId !== null && (int) $y->user_id === $userId,
                'kullanici_adi' => $this->adSonHarf((string) $y->name),
                'puan'          => $kisiPuani[$y->user_id] ?? 0,
                'yorum'         => $y->yorum,
                'tarih'         => $y->updated_at,
            ])->all(),
            'salon_id'     => $salonlar[0],
            'kullanici_yorum_yapabilir' => $userId !== null && ! $yorumlar->contains(fn ($y) => (int) $y->user_id === $userId),
        ];
    }

    private function adSonHarf(string $ad): string
    {
        $parcalar = preg_split('/\s+/', trim($ad));
        if (trim($ad) === '') {
            return 'Müşteri';
        }
        if (count($parcalar) === 1) {
            return $parcalar[0];
        }
        $son = array_pop($parcalar);

        return implode(' ', $parcalar).' '.mb_substr($son, 0, 1, 'UTF-8').'.';
    }

    public function vitrin(int $salonId): array
    {
        $salon = $this->kapi->salon($salonId);
        $personeller = $this->secenek->personeller($salonId);
        $hizmetler = $this->secenek->hizmetler($salonId);
        $puan = $this->puanOzeti($salonId);

        return [
            'salon'          => $this->marka($salon) + [
                'aciklama'         => $salon->aciklama,
                'meta_description' => $salon->meta_description,
                'adres'            => $salon->adres,
                'telefon_1'        => $salon->telefon_1,
                'instagram_sayfa'  => $salon->instagram_sayfa,
                'facebook_sayfa'   => $salon->facebook_sayfa,
                'maps_iframe'      => $salon->maps_iframe,
                'logo'             => EskiPanelLinki::mutlak($salon->logo),
                'tema_seed'        => $salon->tema_seed,
                'salon_turu_adi'   => DB::table('salon_turu')->where('id', $salon->salon_turu_id)->value('salon_turu_adi'),
                'il_adi'           => DB::table('il')->where('id', $salon->il_id)->value('il_adi'),
                'ilce_adi'         => DB::table('ilce')->where('id', $salon->ilce_id)->value('ilce_adi'),
            ],
            'calisma_saatleri' => $this->calismaSaatleri($salonId),
            'kategoriler'    => $this->kategoriler($hizmetler),
            'hizmetler'      => $hizmetler,
            'personeller'    => $personeller,
            'galeri'         => $this->galeri($salonId),
            'kapak_gorseli'  => EskiPanelLinki::mutlak(DB::table('salon_gorselleri')->where('salon_id', $salonId)->where('kapak_fotografi', 1)->value('salon_gorseli')),
            'arama_terimleri'=> DB::table('arama_terimleri')->where('salon_id', $salonId)->orderBy('id')->pluck('arama_terimi')->all(),
            'puan'           => $puan,
            'one_cikan_yorumlar' => $this->anketYorumlari($salonId),
            // Hediye carki: aktif ve en az 2 dilimliyse cizim verisi (olasilik yok), degilse null.
            'cark'           => $this->cark->vitrinCarki($salonId),
        ];
    }

    /** v1 salonyorumlari alanlari; ad maskeli, telefon yok. */
    public function yorumlar(int $salonId, ?int $userId): array
    {
        $this->kapi->salon($salonId);
        $sayfa = DB::table('salon_yorumlar as y')->leftJoin('users as u', 'u.id', '=', 'y.user_id')
            ->where('y.salon_id', $salonId)->orderByDesc('y.updated_at')
            ->paginate(self::YORUM_SAYFA, ['y.id', 'y.user_id', 'y.yorum', 'y.updated_at', 'u.name', 'u.profil_resim']);
        $puanlar = DB::table('salon_puanlar')->where('salon_id', $salonId)->whereBetween('puan', [1, 5])
            ->whereIn('user_id', array_column($sayfa->items(), 'user_id'))->pluck('puan', 'user_id');

        return $this->puanOzeti($salonId) + [
            'salon_id'  => $salonId,
            'kullanici_yorum_yapabilir' => $userId !== null
                && DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->exists(),
            'yorumlar'  => array_map(fn ($y) => [
                'id'            => (int) $y->id,
                'kullanici_adi' => KvkkMaske::adSoyad($y->name) ?: 'Müşteri',
                'profil_resim'  => EskiPanelLinki::mutlak($y->profil_resim),
                'puan'          => (int) round((float) ($puanlar[$y->user_id] ?? 0)),
                'yorum'         => $y->yorum,
                'tarih'         => $y->updated_at,
            ], $sayfa->items()),
            'current_page' => $sayfa->currentPage(),
            'last_page'    => $sayfa->lastPage(),
        ];
    }

    /** Sayfa acilis kaydi (eski IsletmeZiyaretciler). Tarayici disi istemciler sayilmaz. */
    public function ziyaretYaz(int $salonId, string $ip, string $tarayici): void
    {
        if ($tarayici === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $tarayici)) {
            return;
        }
        DB::table('isletme_ziyaretciler')->insert([
            'salon_id' => $salonId, 'ipadres' => $ip, 'browser' => mb_substr($tarayici, 0, 255),
            'arama_terimi_id' => DB::table('arama_terimleri')->where('salon_id', $salonId)->orderBy('id')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function marka(object $s): array
    {
        return [
            'id'                           => (int) $s->id,
            'salon_adi'                    => $s->salon_adi,
            'domain'                       => $s->domain,
            'app_bundle'                   => $s->app_bundle,
            'lisans_aktif'                 => $this->kapi->lisansAktif($s),
            'uyelik_bitis_tarihi'          => $s->uyelik_bitis_tarihi,
            'musteri_online_randevu_aktif' => (int) ($s->musteri_online_randevu_aktif ?? 0) === 1,
            'studyo_modu'                  => (bool) ($s->studyo_modu ?? false),
            'grup_dersi_aktif'             => (bool) ($s->grup_dersi_aktif ?? false),
            'randevu_saat_araligi'         => (int) ($s->randevu_saat_araligi ?: 30),
            'randevu_takvim_turu'          => $s->randevu_takvim_turu ?? null,
        ];
    }

    private function puanOzeti(int $salonId): array
    {
        // salon_puanlar carkifelek sadakat puanini da tutar: yalniz 1-5 yildizdir.
        $puanlar = DB::table('salon_puanlar')->where('salon_id', $salonId)->whereBetween('puan', [1, 5])->pluck('puan');
        $dagilim = array_fill_keys(['1', '2', '3', '4', '5'], 0);
        foreach ($puanlar as $p) {
            $dagilim[(string) (int) round((float) $p)]++;
        }

        return [
            'ortalama'     => $puanlar->count() ? round((float) $puanlar->avg(), 1) : 0,
            'toplam_puan'  => $puanlar->count(),
            'toplam_yorum' => DB::table('salon_yorumlar')->where('salon_id', $salonId)->count(),
            'dagilim'      => $dagilim,
        ];
    }

    private function calismaSaatleri(int $salonId): array
    {
        return DB::table('salon_calisma_saatleri')->where('salon_id', $salonId)->orderBy('haftanin_gunu')
            ->get(['haftanin_gunu', 'calisiyor', 'baslangic_saati', 'bitis_saati'])
            ->map(fn ($s) => [
                'haftanin_gunu'   => (int) $s->haftanin_gunu,
                'calisiyor'       => (int) $s->calisiyor === 1,
                'baslangic_saati' => $s->baslangic_saati ? substr((string) $s->baslangic_saati, 0, 5) : null,
                'bitis_saati'     => $s->bitis_saati ? substr((string) $s->bitis_saati, 0, 5) : null,
            ])->all();
    }

    private function kategoriler(array $hizmetler): array
    {
        $sonuc = [];
        foreach ($hizmetler as $h) {
            $id = (int) ($h['kategori_id'] ?? 0);
            $sonuc[$id] ??= ['id' => $id, 'ad' => $h['kategori_adi'] ?? '', 'hizmet_sayisi' => 0, 'satirlar' => []];
            $sonuc[$id]['hizmet_sayisi']++;
            $sonuc[$id]['satirlar'][] = $h;
        }

        return array_values($sonuc);
    }

    private function galeri(int $salonId): array
    {
        return DB::table('salon_gorselleri')->where('salon_id', $salonId)->orderBy('id')->pluck('salon_gorseli')
            ->reject(fn ($g) => str_contains((string) $g, self::GIZLI_GORSEL_PARCALARI[0]))
            ->map(fn ($g) => EskiPanelLinki::mutlak($g))->filter()->values()->all();
    }

    /** Yuksek puanli, sahibin gizlemedigi anket yorumlari; ad maskeli, telefon yok. */
    private function anketYorumlari(int $salonId): array
    {
        return DB::table('anket_gonderimleri')->where('salon_id', $salonId)->where('cevaplandi', 1)->where('web_gizle', 0)
            ->whereNotNull('genel_yorum')->where('genel_yorum', '!=', '')
            ->where(fn ($q) => $q->where('nps_skoru', '>=', self::ANKET_MIN_NPS)->orWhere('csat_skoru', '>=', self::ANKET_MIN_CSAT))
            ->orderByDesc('cevap_zamani')->limit(self::ANKET_YORUM_LIMIT)
            ->get(['ad_soyad', 'genel_yorum', 'csat_skoru', 'cevap_zamani', 'updated_at'])
            ->map(function ($a) {
                $yildiz = $a->csat_skoru ? (int) round((float) $a->csat_skoru) : 5;

                return [
                    'kullanici_adi' => KvkkMaske::adSoyad($a->ad_soyad) ?: 'Müşteri',
                    'puan'          => $yildiz >= 1 && $yildiz <= 5 ? $yildiz : 5,
                    'yorum'         => $a->genel_yorum,
                    'tarih'         => $a->cevap_zamani ?: $a->updated_at,
                ];
            })->all();
    }
}
