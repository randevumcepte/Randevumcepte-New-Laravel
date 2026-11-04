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
            'yorumlar'             => $this->vitrin->yorumlar($salonId, null)['yorumlar'],
            'indekslenebilir'      => $kendiDomaini,
        ] + $this->ortak($salonId, (object) $v['salon']));
    }

    public function hesap(int $salonId, string $sayfa)
    {
        $salon = $this->kapi->salon($salonId);
        $gorunum = (object) [
            'id' => (int) $salon->id, 'salon_adi' => $salon->salon_adi, 'logo' => EskiPanelLinki::mutlak($salon->logo), 'tema_seed' => $salon->tema_seed,
        ];

        return view('site.hesap.'.$sayfa, ['salon' => $gorunum, 'aktif' => $sayfa] + $this->ortak($salonId, $gorunum));
    }

    private function ortak(int $salonId, object $salon): array
    {
        $taban = '/site/'.$salonId;

        return [
            'tema_css' => SalonTemaPalet::cssOverride($salon->tema_seed ?? null),
            'linkler'  => [
                'ana'        => $taban,
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
