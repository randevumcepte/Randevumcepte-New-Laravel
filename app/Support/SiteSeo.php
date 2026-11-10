<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * İşletme sitesi sayfa başlığı, açıklama, h1, canonical, anahtar kelime ve schema.org JSON-LD — TEK KAYNAK.
 * Saf kural: veri okumaz, VitrinServisi çıktısından üretir (kök, /{ad}-{id}, arama terimi sayfası, personel).
 *
 * Eskiden başlık "{ilk arama terimi} | {salon}" idi; terim yoksa " | Salon" gibi boş başlık, meta_description boşsa
 * boş açıklama çıkıyordu. Burada terim yoksa salon adı + tür + konum, açıklama boşsa salon bilgisinden üretilir.
 */
final class SiteSeo
{
    private const ACIKLAMA_SINIR = 160;

    private const VARSAYILAN_GORSEL = 'public/img/randevumcepte.jpg';

    /** schema.org gün adları; anahtar haftanin_gunu (1 = Pazartesi, date('N') ile aynı). */
    private const GUNLER = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * Vitrin ve varyantları. $sayfaTerimi yalnız arama terimi açılış sayfasında verilir: başlık, h1 ve açıklama
     * o terime göre kurulur. Kökte başlığa ilk arama terimi girer (eski davranış), h1 salon adıdır.
     *
     * @param  array  $v  VitrinServisi::vitrin() çıktısı
     */
    public static function vitrin(array $v, string $kokUrl, ?string $sayfaTerimi = null): array
    {
        $s = $v['salon'];
        $terimler = self::terimler($v['arama_terimleri'] ?? []);
        $sayfaTerimi = self::temiz($sayfaTerimi) ?: null;
        $basTerim = $sayfaTerimi ?? ($terimler[0] ?? null);
        $aciklama = self::temiz($s['meta_description'] ?? null) ?: self::temiz($s['aciklama'] ?? null) ?: self::uretilmisAciklama($s, $sayfaTerimi);
        $aciklama = Str::limit($aciklama, self::ACIKLAMA_SINIR);

        return [
            'baslik'            => $basTerim ? ucwords($basTerim).' | '.$s['salon_adi'] : self::salonBasligi($s),
            'aciklama'          => $aciklama,
            'h1'                => $sayfaTerimi ? ucwords($sayfaTerimi) : $s['salon_adi'],
            // Terim ve /{ad}-{id} sayfaları kökün varyantıdır: eskisi gibi canonical kök (site haritasında yalnız kök var).
            'canonical'         => $kokUrl,
            'og_tur'            => 'website',
            'anahtar_kelimeler' => array_values(array_unique(array_filter([$sayfaTerimi, ...$terimler]))),
            'gorsel'            => self::gorsel($v['kapak_gorseli'] ?? null, $s['logo'] ?? null),
            'ld_json'           => self::isletmeLd($v, $kokUrl, $aciklama),
        ];
    }

    /**
     * Personel tanıtım sayfası (eski personelDetayPublic: başlık "{ad} | {salon}").
     *
     * @param  array  $salon  VitrinServisi::vitrin()['salon']
     * @param  array  $p  OnlineSecenekServisi::personeller() satırı
     */
    public static function personel(array $salon, array $p, string $url, string $kokUrl, ?string $resim): array
    {
        $unvan = self::temiz($p['uzmanlik'] ?? null) ?: self::temiz($p['unvan'] ?? null);
        $aciklama = self::temiz($p['aciklama'] ?? null)
            ?: trim($p['personel_adi'].', '.$salon['salon_adi'].' ekibinde'.($unvan ? ' '.$unvan : '').'. Online randevunuzu hemen alın.');

        return [
            'baslik'            => $p['personel_adi'].' | '.$salon['salon_adi'],
            'aciklama'          => Str::limit($aciklama, self::ACIKLAMA_SINIR),
            'h1'                => $p['personel_adi'],
            'canonical'         => $url,
            'og_tur'            => 'profile',
            'anahtar_kelimeler' => [],
            'gorsel'            => self::gorsel($resim, $salon['logo'] ?? null),
            'ld_json'           => array_filter([
                '@context' => 'https://schema.org', '@type' => 'Person', 'name' => $p['personel_adi'], 'jobTitle' => $unvan ?: null,
                'description' => self::temiz($p['aciklama'] ?? null) ?: null, 'image' => $resim, 'url' => $url,
                'sameAs'   => array_values(array_filter([self::sosyalUrl($p['instagram'] ?? null, 'https://instagram.com/')])) ?: null,
                'worksFor' => ['@type' => 'LocalBusiness', 'name' => $salon['salon_adi'], 'url' => $kokUrl],
            ]),
        ];
    }

    /** Eski adres biçimi /{salon-adi-slug}-{id} (str_slug ile aynı). */
    public static function salonYolu(int $salonId, ?string $salonAdi): string
    {
        return '/'.(Str::slug((string) $salonAdi) ?: 'salon').'-'.$salonId;
    }

    /** Personel resmi; yoksa cinsiyete göre varsayılan (vitrin ekibi ve personel sayfası aynı). */
    public static function personelResmi(array $p): string
    {
        return ($p['profil_resmi'] ?? null) ?: EskiPanelLinki::mutlak((int) ($p['cinsiyet'] ?? 0) === 0 ? 'public/img/author0.jpg' : 'public/img/author1.jpg');
    }

    /** "@kullanici" ya da çıplak ad → tam profil adresi; zaten adresse aynen. */
    public static function sosyalUrl(?string $deger, string $taban): ?string
    {
        $deger = trim((string) $deger);
        if ($deger === '') {
            return null;
        }

        return preg_match('#^https?://#i', $deger) ? $deger : $taban.ltrim($deger, '@');
    }

    /** "İlçe/İl" (biri yoksa diğeri). */
    public static function konum(array $s): string
    {
        return implode('/', array_filter([self::temiz($s['ilce_adi'] ?? null), self::temiz($s['il_adi'] ?? null)]));
    }

    private static function salonBasligi(array $s): string
    {
        $ek = implode(' - ', array_filter([self::temiz($s['salon_turu_adi'] ?? null), self::konum($s)]));

        return $s['salon_adi'].' | '.($ek ?: 'Online Randevu');
    }

    private static function uretilmisAciklama(array $s, ?string $terim): string
    {
        $bilgi = implode(', ', array_filter([self::temiz($s['salon_turu_adi'] ?? null), self::konum($s)]));

        return ($terim ? ucwords($terim).' için ' : '').$s['salon_adi'].($bilgi ? ' ('.$bilgi.')' : '')
            .'. Hizmetleri ve müsait saatleri inceleyin, online randevunuzu hemen alın.';
    }

    private static function isletmeLd(array $v, string $kokUrl, string $aciklama): array
    {
        $s = $v['salon'];
        $puan = $v['puan'] ?? [];

        return array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            'name'        => $s['salon_adi'],
            'description' => $aciklama,
            'url'         => $kokUrl,
            'telephone'   => self::temiz($s['telefon_1'] ?? null) ?: null,
            'image'       => $v['kapak_gorseli'] ?? null,
            'logo'        => $s['logo'] ?? null,
            'address'     => array_filter([
                '@type' => 'PostalAddress', 'streetAddress' => self::temiz($s['adres'] ?? null) ?: null,
                'addressLocality' => self::temiz($s['ilce_adi'] ?? null) ?: null, 'addressRegion' => self::temiz($s['il_adi'] ?? null) ?: null,
                'addressCountry' => 'TR',
            ]),
            'openingHoursSpecification' => self::calismaSaatleri($v['calisma_saatleri'] ?? []) ?: null,
            'aggregateRating' => ($puan['toplam_puan'] ?? 0) > 0 ? array_filter([
                '@type' => 'AggregateRating', 'ratingValue' => $puan['ortalama'], 'ratingCount' => $puan['toplam_puan'],
                'reviewCount' => $puan['toplam_yorum'] ?? null, 'bestRating' => 5, 'worstRating' => 1,
            ]) : null,
            'sameAs' => array_values(array_filter([
                self::sosyalUrl($s['facebook_sayfa'] ?? null, 'https://facebook.com/'),
                self::sosyalUrl($s['instagram_sayfa'] ?? null, 'https://instagram.com/'),
            ])) ?: null,
        ], fn ($x) => $x !== null && $x !== '');
    }

    private static function calismaSaatleri(array $saatler): array
    {
        $sonuc = [];
        foreach ($saatler as $c) {
            $c = (array) $c;
            $gun = self::GUNLER[(int) ($c['haftanin_gunu'] ?? 0)] ?? null;
            if ($gun && ! empty($c['calisiyor']) && ! empty($c['baslangic_saati']) && ! empty($c['bitis_saati'])) {
                $sonuc[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $gun,
                    'opens' => substr((string) $c['baslangic_saati'], 0, 5), 'closes' => substr((string) $c['bitis_saati'], 0, 5)];
            }
        }

        return $sonuc;
    }

    /** @return string[] boşları atılmış, sırası korunmuş terimler */
    private static function terimler(array $terimler): array
    {
        return array_values(array_filter(array_map(fn ($t) => self::temiz($t), $terimler), fn ($t) => $t !== ''));
    }

    private static function gorsel(?string ...$adaylar): string
    {
        foreach ($adaylar as $a) {
            if (trim((string) $a) !== '') {
                return $a;
            }
        }

        return EskiPanelLinki::mutlak(self::VARSAYILAN_GORSEL);
    }

    private static function temiz(?string $metin): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $metin), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
