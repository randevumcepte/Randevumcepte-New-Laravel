<?php

namespace App\Http\Controllers;

use App\Services\Online\OnlineSalonKapisi;
use App\Services\Online\SiteHaritaServisi;
use Illuminate\Http\Request;

/**
 * Arama motoru uçları (eski HomeController@sitemap, robots, salonlarSitemapIndex, salonlarDizini). Is kurali icermez.
 *
 * - /sitemap.xml: yalnız salonun kendi alan adında; başka host'ta 404.
 * - /robots.txt: salon alan adında salonun haritası, panel host'unda merkezi index gösterilir.
 * - /salonlar-sitemap.xml, /salonlar-dizini: merkezi; salon alan adında 404 (dizin her salon sitesinde tekrar etmesin).
 */
class SiteHaritaController extends Controller
{
    public function __construct(private SiteHaritaServisi $harita, private OnlineSalonKapisi $kapi) {}

    public function sitemap(Request $request)
    {
        $salonId = $this->salonId($request);
        abort_unless($salonId, 404);

        return $this->xml($this->harita->salonSitemap($salonId));
    }

    public function robots(Request $request)
    {
        return response($this->harita->robots($this->kok($request), $this->salonId($request) !== null), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function salonlarSitemap(Request $request)
    {
        abort_if($this->salonId($request), 404);

        return $this->xml($this->harita->salonlarSitemapIndex());
    }

    public function salonlarDizini(Request $request)
    {
        abort_if($this->salonId($request), 404);

        return view('site.salonlar-dizini', ['salonlar' => $this->harita->dizindekiSalonlar(), 'canonical' => $this->kok($request).'/salonlar-dizini']);
    }

    private function salonId(Request $request): ?int
    {
        return $this->kapi->domaindenSalonId($request->getHost());
    }

    private function kok(Request $request): string
    {
        return 'https://'.$request->getHost();
    }

    private function xml(string $xml)
    {
        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
