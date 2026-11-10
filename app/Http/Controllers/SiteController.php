<?php

namespace App\Http\Controllers;

use App\Domain\Ayar\SalonTemaPalet;
use App\Services\Online\OnlineSalonKapisi;
use App\Services\Online\VitrinServisi;
use App\Support\EskiPanelLinki;
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
        $salonId = $this->kapi->domaindenSalonId($request->getHost());
        abort_unless($salonId, 404);

        return $this->hesap($request, $salonId, $sayfa);
    }

    public function vitrin(Request $request, int $salonId)
    {
        $v = $this->vitrin->vitrin($salonId);
        $kendiDomaini = $this->kendiDomaini($request, $v['salon']['domain'] ?? null);
        if ($kendiDomaini) {
            $this->vitrin->ziyaretYaz($salonId, (string) $request->ip(), (string) $request->userAgent());
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

    private function kendiDomaini(Request $request, ?string $domain): bool
    {
        $temiz = fn (?string $h) => preg_replace('/^www\./i', '', mb_strtolower((string) $h));

        return $domain && $temiz($request->getHost()) === $temiz($domain);
    }
}
