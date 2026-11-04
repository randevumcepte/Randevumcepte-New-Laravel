<?php

namespace App\Domain\Anket;

/**
 * Yapıştırılan Google bağlantısı / Place ID → doğrudan yorum yazma URL'si (eski googlePlaceIdParse) — SAF.
 * Kısa bağlantı genişletme ağ işidir; çağıran $genislet ile verir (test edilebilirlik).
 *
 * Sıra: Place ID · placeid= · g.page/r/ · (kısa URL genişlet) · !1s FID · cid= · diğer http(s) (uyarılı).
 */
final class GoogleYorumBaglantisi
{
    private const YAZ = 'https://search.google.com/local/writereview';
    private const KISA_URL = '#^https?://(maps\.app\.goo\.gl|goo\.gl/maps|maps\.google\.com/url)#i';
    private const HARITA_UYARISI = 'Bu link harita sayfasını açar, doğrudan yorum yazma diyaloğunu açmayabilir. En güvenli yol: Google Business hesabınızdan "Yorum Bağlantısı"nı kopyalayıp yapıştırın (g.page/r/... formatında olmalı).';
    public const GECERSIZ = 'Geçerli bir URL veya Place ID bulunamadı.';

    /**
     * @param  ?callable(string):?string  $genislet  kısa URL'nin vardığı adres (hata → null)
     * @return array{place_id:?string, review_url:?string, uyari:?string}
     */
    public static function coz(string $girdi, ?callable $genislet = null): array
    {
        $girdi = trim($girdi);
        if ($girdi === '') {
            return self::sonuc();
        }
        if (preg_match('/^(ChIJ|GhIJ|EhIJ|EkIJ)[A-Za-z0-9_-]{20,}$/', $girdi)) {
            return self::placeId($girdi);
        }
        if (preg_match('/placeid=([A-Za-z0-9_-]+)/', $girdi, $m)) {
            return self::placeId($m[1]);
        }
        if (preg_match('#g\.page/r/([A-Za-z0-9_-]+)#', $girdi, $m)) {
            return self::sonuc(null, 'https://g.page/r/'.$m[1].'/review');
        }

        $acik = $girdi;
        if ($genislet && preg_match(self::KISA_URL, $girdi)) {
            $acik = $genislet($girdi) ?: $girdi;
        }
        if (preg_match('#!1s(0x[a-f0-9]+):(0x[a-f0-9]+)#i', $acik, $m)) {
            return self::sonuc(null, self::YAZ.'?ftid='.$m[1].':'.$m[2]);
        }
        if (preg_match('#[?&]cid=(\d+)#', $acik, $m)) {
            return self::sonuc(null, self::YAZ.'?ftid=0x0:0x'.dechex((int) $m[1]));
        }
        if (preg_match('#^https?://#i', $girdi)) {
            return self::sonuc(null, $girdi, self::HARITA_UYARISI);
        }

        return self::sonuc(null, null, self::GECERSIZ);
    }

    private static function placeId(string $id): array
    {
        return self::sonuc($id, self::YAZ.'?placeid='.$id);
    }

    private static function sonuc(?string $placeId = null, ?string $url = null, ?string $uyari = null): array
    {
        return ['place_id' => $placeId, 'review_url' => $url, 'uyari' => $uyari];
    }
}
