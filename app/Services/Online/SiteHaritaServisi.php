<?php

namespace App\Services\Online;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arama motoru dosyaları: salonun kendi alan adında /sitemap.xml ve /robots.txt, merkezi host'ta
 * /salonlar-sitemap.xml ve /salonlar-dizini (eski HomeController@sitemap, robots, salonlarSitemapIndex, salonlarDizini).
 *
 * XML düz metin üretilir (eskisi gibi Blade yok: short_open_tag açık sunucuda "<?xml" PHP etiketi sanılıyordu).
 */
class SiteHaritaServisi
{
    public function __construct(private OnlineSalonKapisi $kapi) {}

    /**
     * Salon sitesinin haritası: yalnız kök adres (eski karar: /{ad}-{id} ve arama terimi sayfaları canonical ile
     * köke bağlı olduğundan listelenmez). Adres canonical ile aynı: salonun kayıtlı alan adı. lastmod salon kaydının
     * son güncellemesi, yoksa bugün.
     */
    public function salonSitemap(int $salonId): string
    {
        $salon = $this->kapi->salon($salonId);

        return $this->xml('urlset', [[
            'loc' => 'https://'.$this->host($salon->domain).'/', 'lastmod' => $this->tarih($salon->updated_at ?? null), 'changefreq' => 'weekly', 'priority' => '1.0',
        ]]);
    }

    /** Lisansı süren her salonun kendi /sitemap.xml'i (Search Console'a tek seferde verilen index). */
    public function salonlarSitemapIndex(): string
    {
        return $this->xml('sitemapindex', $this->dizindekiSalonlar()->map(fn ($s) => [
            'loc' => 'https://'.$s->host.'/sitemap.xml', 'lastmod' => $this->tarih($s->updated_at),
        ])->all(), 'sitemap');
    }

    /**
     * Dizinde ve index'te yer alan salonlar: alan adı tanımlı, üyelik bitiş tarihi bugün ya da sonrası (eski filtre:
     * tarihi boş olan da listelenmez). Salon adına göre sıralı.
     *
     * @return Collection<int,object{host:string,salon_adi:?string,il_adi:?string,ilce_adi:?string,updated_at:mixed}>
     */
    public function dizindekiSalonlar(): Collection
    {
        return DB::table('salonlar as s')
            ->leftJoin('il', 'il.id', '=', 's.il_id')->leftJoin('ilce', 'ilce.id', '=', 's.ilce_id')
            ->whereNotNull('s.domain')->where('s.domain', '!=', '')
            ->whereNotNull('s.uyelik_bitis_tarihi')->where('s.uyelik_bitis_tarihi', '!=', '')
            ->whereDate('s.uyelik_bitis_tarihi', '>=', now()->toDateString())
            ->orderBy('s.salon_adi')
            ->get(['s.domain', 's.salon_adi', 's.updated_at', 'il.il_adi', 'ilce.ilce_adi'])
            ->map(function ($s) {
                $s->host = $this->host($s->domain);

                return $s;
            })
            ->filter(fn ($s) => $s->host !== '')->values();
    }

    /**
     * robots.txt. Salon alan adında eski içerik + müşteri hesap sayfaları ve API; harita /sitemap.xml.
     * Panel (salon olmayan) host'ta panel ve API kapalı, harita merkezi index.
     */
    public function robots(string $kokUrl, bool $salonSitesi): string
    {
        $kapali = $salonSitesi
            ? ['/isletmeyonetim/', '/admin/', '/api/', '/profilim', '/randevularim', '/sifrem']
            : ['/api/', '/v2/', '/sistemyonetim/', '/isletmeyonetim/', '/admin/'];
        $harita = rtrim($kokUrl, '/').($salonSitesi ? '/sitemap.xml' : '/salonlar-sitemap.xml');

        return "User-agent: *\n".implode('', array_map(fn ($y) => 'Disallow: '.$y."\n", $kapali))."\nSitemap: ".$harita."\n";
    }

    /** @param  array<int,array<string,string>>  $girdiler */
    private function xml(string $kok, array $girdiler, string $oge = 'url'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<'.$kok.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($girdiler as $g) {
            $xml .= '  <'.$oge.'>'."\n";
            foreach ($g as $ad => $deger) {
                $xml .= '    <'.$ad.'>'.htmlspecialchars((string) $deger, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$ad.'>'."\n";
            }
            $xml .= '  </'.$oge.'>'."\n";
        }

        return $xml.'</'.$kok.'>';
    }

    /** Kayıtlı alan adı → host (protokol ve sondaki / atılır, eski biçim). */
    private function host(?string $domain): string
    {
        return trim(preg_replace('#^https?://#i', '', trim((string) $domain)), '/');
    }

    private function tarih($deger): string
    {
        $zaman = $deger ? strtotime((string) $deger) : false;

        return date('Y-m-d', $zaman ?: time());
    }
}
