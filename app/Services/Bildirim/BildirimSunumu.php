<?php

namespace App\Services\Bildirim;

use App\Domain\Bildirim\BildirimHedefi;
use App\Support\EskiPanelLinki;
use App\Support\PanelMenu;

/**
 * Bildirim satırının API biçimi — alan adları Flutter SistemBildirimleri.fromJson ile aynı.
 *
 * `okundu` 0/1 sayıdır (Flutter `okundu == '1'` karşılaştırır). Eski uçlar user/personel/randevu
 * kayıtlarını bütün kişisel verisiyle döküyordu; burada yalnız ekranın kullandığı alanlar var.
 */
final class BildirimSunumu
{
    public static function satir(object $r, ?string $arsivUzanti): array
    {
        return [
            'id'         => (int) $r->id,
            'salon_id'   => $r->salon_id === null ? null : (int) $r->salon_id,
            'baslik'     => $r->baslik ?? null,
            'aciklama'   => $r->aciklama,
            'tarih_saat' => $r->tarih_saat,
            'okundu'     => (int) (bool) $r->okundu,
            'tip'        => $r->tip ?? null,
            'deep_link'  => $r->deep_link ?? null,
            'url'        => $r->url,
            'img_src'    => $r->img_src,
            'resim_url'  => EskiPanelLinki::mutlak($r->img_src),
            'popup'      => (bool) ($r->popup ?? false),
            'randevu_id' => $r->randevu_id === null ? null : (int) $r->randevu_id,
            'arsiv'      => $arsivUzanti ? ['uzanti' => $arsivUzanti] : null,
        ];
    }

    /** Panel satırı = temel + dokununca gidilecek hedef (web_yol: v2 içi hazır adres). */
    public static function panel(object $r, ?string $arsivUzanti, ?int $randevuMusterisi, int $salonId): array
    {
        $hedef = BildirimHedefi::coz(
            $r->url,
            [$r->img_src, $r->image_url ?? null],
            $randevuMusterisi,
            $r->user_id === null ? null : (int) $r->user_id,
        );

        return self::satir($r, $arsivUzanti) + ['hedef' => $hedef->dizi() + ['web_yol' => self::webYolu($hedef, $salonId)]];
    }

    /** Eski panele yönlendirme yapılmaz: v2 karşılığı olmayan hedef randevu listesine düşer. */
    private static function webYolu(BildirimHedefi $h, int $salonId): string
    {
        $sorgu = '?'.http_build_query(['sube' => $salonId]);
        $randevular = '/v2/randevular'.$sorgu;

        return match ($h->tur) {
            BildirimHedefi::MUSTERI => '/v2/musteri/'.$h->musteriId.$sorgu,
            BildirimHedefi::URL     => self::urlYolu((string) $h->url, $salonId) ?? $randevular,
            default                 => $randevular,
        };
    }

    /** Dış adres ve /v2 yolu olduğu gibi; /isletmeyonetim/<slug> menüdeki v2 karşılığına çevrilir. */
    private static function urlYolu(string $url, int $salonId): ?string
    {
        if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/v2/')) {
            return $url;
        }
        if (preg_match('#^/isletmeyonetim/([a-z0-9_-]+)#i', $url, $m) && ($menu = PanelMenu::bul($m[1]))) {
            return PanelMenu::url($menu, $salonId);
        }

        return null;
    }
}
