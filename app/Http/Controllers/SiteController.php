<?php

namespace App\Http\Controllers;

use App\Domain\Ayar\SalonTemaPalet;
use App\Services\Online\OnlineSalonKapisi;
use App\Services\Online\VitrinServisi;
use App\Support\EskiPanelLinki;
use App\Support\SiteSeo;
use Illuminate\Http\Request;

/**
 * Isletme sitesi + musteri paneli sayfalari (SEO icin sunucuda cizilir; veri /api/v2 ile ayni
 * servisten, etkilesim /api/v2 uzerinden). Is kurali icermez.
 *
 * Gecis oncesi onizleme: /site/{salonId}. Host salonun kendi domain'i degilse sayfa indekslenmez
 * ve ziyaret sayilmaz.
 */
class SiteController extends Controller
{
    public function __construct(private VitrinServisi $vitrin, private OnlineSalonKapisi $kapi) {}

    /**
     * Kök adres (eski HomeController@salonDetay): alan adı bir salona aitse o salonun sitesi açılır. Değilse panel
     * adresidir, girişe yönlenir (eskiden boş Laravel karşılama sayfası görünüyordu).
     */
    public function kok(Request $request)
    {
        $salonId = $this->kapi->domaindenSalonId($request->getHost());

        return $salonId ? $this->vitrin($request, $salonId) : redirect('/v2/giris');
    }

    /** Salonun kendi alan adında hesap sayfaları (/giris, /profilim, /randevularim, /sifrem). */
    public function kokHesap(Request $request, string $sayfa)
    {
        return $this->hesap($request, $this->kokSalonu($request), $sayfa);
    }

    /** Önizleme /site/{salonId} (salonun kendi alan adında değilse noindex). */
    public function vitrin(Request $request, int $salonId)
    {
        return $this->vitrinSayfasi($request, $salonId);
    }

    /** Eski /{isletme_adi}-{isletme_id}: kökün varyantı; id bu alan adının salonu değilse 404. Ad kısmına bakılmaz. */
    public function salonSayfasi(Request $request, string $isletmeAdi, int $isletmeId)
    {
        return $this->vitrinSayfasi($request, $this->kokSalonu($request, $isletmeId));
    }

    /**
     * Eski arama terimi açılış sayfası /{tür}/{il}/{ilçe}/{id}/{ad}/{terim}/{terim_id}: başlık, açıklama ve h1 terime
     * göre. Yalnız id ve terim_id okunur (eskisi gibi); salon bu alan adına, terim bu salona ait değilse 404.
     */
    public function aramaTerimiSayfasi(Request $request, string $isletmeTuru, string $il, string $ilce, int $isletmeId, string $isletmeAdi, string $aramaTerimi, int $aramaTerimId)
    {
        $salonId = $this->kokSalonu($request, $isletmeId);
        $terim = $this->vitrin->aramaTerimi($salonId, $aramaTerimId);
        abort_unless($terim !== null, 404);

        return $this->vitrinSayfasi($request, $salonId, $terim, $aramaTerimId);
    }

    /** Eski /{isletme_adi}-{isletme_id}/personel/{personel_id}: vitrinde görünen personelin tanıtım sayfası. */
    public function personel(Request $request, string $isletmeAdi, int $isletmeId, int $personelId)
    {
        $salonId = $this->kokSalonu($request, $isletmeId);
        $detay = $this->vitrin->personelDetay($salonId, $personelId);
        abort_unless($detay !== null, 404);
        $v = $this->vitrin->vitrin($salonId);
        $salon = (object) $v['salon'];
        $kokUrl = $this->kokUrl($request, $salon->domain);
        $salonYolu = SiteSeo::salonYolu($salonId, $salon->salon_adi);
        $resim = SiteSeo::personelResmi($detay['personel']);

        return view('site.personel', [
            'salon'           => $salon,
            'personel'        => $detay['personel'],
            'resim'           => $resim,
            'hizmetler'       => $detay['hizmetler'],
            'digerleri'       => $detay['digerleri'],
            'salonYolu'       => $salonYolu,
            'indekslenebilir' => true,
            'vitrinBetikleri' => false,
            'seo'             => SiteSeo::personel($v['salon'], $detay['personel'], $kokUrl.$salonYolu.'/personel/'.$personelId, $kokUrl, $resim),
        ] + $this->ortak($salonId, $salon, true));
    }

    private function vitrinSayfasi(Request $request, int $salonId, ?string $terim = null, ?int $terimId = null)
    {
        $v = $this->vitrin->vitrin($salonId);
        $kendiDomaini = $this->kendiDomaini($request, $v['salon']['domain'] ?? null);
        if ($kendiDomaini) {
            $this->vitrin->ziyaretYaz($salonId, (string) $request->ip(), (string) $request->userAgent(), $terimId);
        }

        return view('site.vitrin', [
            'salon'                => (object) $v['salon'],
            'saloncalismasaatleri' => collect($v['calisma_saatleri'])->map(fn ($c) => (object) $c),
            'kategoriler'          => $v['kategoriler'],
            'hizmetler'            => $v['hizmetler'],
            'personeller'          => $v['personeller'],
            'galeri'               => $v['galeri'],
            'kapak_gorseli'        => $v['kapak_gorseli'],
            'arama_terimleri'      => $v['arama_terimleri'],
            'puan'                 => $v['puan'],
            'one_cikan_yorumlar'   => $v['one_cikan_yorumlar'],
            'cark'                 => $v['cark'],
            'yorumlar'             => $this->vitrin->yorumlar($salonId, null)['yorumlar'],
            'indekslenebilir'      => $kendiDomaini,
            'seo'                  => SiteSeo::vitrin($v, $this->kokUrl($request, $v['salon']['domain'] ?? null), $terim),
        ] + $this->ortak($salonId, (object) $v['salon'], $kendiDomaini));
    }

    public function hesap(Request $request, int $salonId, string $sayfa)
    {
        $salon = $this->kapi->salon($salonId);
        $gorunum = (object) [
            'id' => (int) $salon->id, 'salon_adi' => $salon->salon_adi, 'logo' => EskiPanelLinki::mutlak($salon->logo), 'tema_seed' => $salon->tema_seed,
        ];

        return view('site.hesap.'.$sayfa, ['salon' => $gorunum, 'aktif' => $sayfa]
            + $this->ortak($salonId, $gorunum, $this->kendiDomaini($request, $salon->domain ?? null)));
    }

    /** Kendi alan adında linkler kökten (/giris), önizlemede /site/{id} altından verilir. */
    private function ortak(int $salonId, object $salon, bool $kendiDomaini): array
    {
        $taban = $kendiDomaini ? '' : '/site/'.$salonId;

        return [
            'tema_css' => SalonTemaPalet::cssOverride($salon->tema_seed ?? null),
            'linkler'  => [
                'ana'        => $taban ?: '/',
                'giris'      => $taban.'/giris',
                'profil'     => $taban.'/profilim',
                'randevular' => $taban.'/randevularim',
                'sifre'      => $taban.'/sifrem',
                'gizlilik'   => EskiPanelLinki::mutlak('kullanim-ve-gizlik-kosullari'),
            ],
        ];
    }

    /** İsteğin alan adının salonu; alan adı bir salona ait değilse ya da beklenen salon değilse 404. */
    private function kokSalonu(Request $request, ?int $beklenen = null): int
    {
        $salonId = $this->kapi->domaindenSalonId($request->getHost());
        abort_unless($salonId && ($beklenen === null || $salonId === $beklenen), 404);

        return $salonId;
    }

    /** Canonical ve JSON-LD kökü: salonun alan adı (önizlemede de gerçek adres), yoksa istek host'u. */
    private function kokUrl(Request $request, ?string $domain): string
    {
        return 'https://'.($domain ?: $request->getHost());
    }

    private function kendiDomaini(Request $request, ?string $domain): bool
    {
        $temiz = fn (?string $h) => preg_replace('/^www\./i', '', mb_strtolower((string) $h));

        return $domain && $temiz($request->getHost()) === $temiz($domain);
    }
}
