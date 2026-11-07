<?php

namespace App\Domain\Asistan;

/**
 * Mobil uygulamanin gorev satirlari (SAF, DB yok): eski easistandata ve easistandatadashboard SQL'inin
 * CONCAT/CASE sonucu birebir. Webin kartlari GorevSunumu'ndadir, metinler ve kurallar ondan farklidir.
 *
 * Bilerek korunanlar: randevu listesinde 'Ulaşıldı<' yazimi ve cift bosluklar (ekran bugun boyle gosterir),
 * CONCAT'e NULL girerse tum metnin null olmasi (ekranda bos). Tum satirlarin id'si `randevu_id` altindadir
 * (eski UNION'in ilk select alias'i); tur `tur` alanindadir. Ozet gorunumunde tur ve iptal alanlari yoktur.
 */
final class MobilGorevSunumu
{
    public static function randevu(array $s, bool $danisan, MobilGorevGorunumu $g): array
    {
        $liste = $g === MobilGorevGorunumu::Liste;
        $yapildi = self::bir($s, 'hatirlatma_aramasi_yapildi');
        $tekrarSaat = self::saat($s['tekrar_arama_tarih_saat'] ?? null);

        $sonuc = match (true) {
            $yapildi && self::bir($s, 'randevuya_gelecek') => 'Randevuya gelecek.',
            ($s['hatirlatma_aramasi_yapildi'] ?? null) === null => $liste ? 'Randevu hatırlatma araması yapılacak.' : 'Hatırlatma araması yapılacak.',
            self::bir($s, 'hatirlatma_ulasilamadi') => self::zincir($liste ? 'Randevu hatırlatması için , ' : 'Ulaşılamadı, ', $tekrarSaat, ' de tekrar arayacağım'),
            self::bir($s, 'asistan_guncelledi') => self::zincir($liste ? 'Randevu tarihi asistan tarafından ' : 'Asistan tarafından ', self::tarih($s['tarih'] ?? null), ' ', self::saat($s['saat'] ?? null), ' olarak güncellendi'),
            default => $liste ? 'Randevu hatırlatması için ulaşılamadı' : 'Ulaşılamadı',
        };

        $satir = [
            'mesaj'  => self::zincir($s['musteri_ad'] ?? null, ' isimli ', $danisan ? 'danışanın ' : 'müşterinin ', self::tarih($s['tarih'] ?? null), ' randevu hatırlatmasını ', $yapildi ? ' yaptım.' : ' yapacağım.'),
            'sonuc'  => $sonuc,
            'durum'  => self::durum($s, $liste ? 'Ulaşıldı<' : 'Ulaşıldı'),
            'baslik' => $liste ? ($s['musteri_ad'] ?? null) : 'Randevu Hatırlatması',
            'saat'   => self::saat($yapildi ? ($s['arama_saat'] ?? null) : ($s['saat'] ?? null)),
        ];

        return self::tamamla($satir, $s, GorevTuru::Randevu, $g);
    }

    public static function alacak(array $s, bool $danisan, MobilGorevGorunumu $g): array
    {
        $liste = $g === MobilGorevGorunumu::Liste;
        $yapildi = self::bir($s, 'hatirlatma_aramasi_yapildi');
        $ulasildi = self::sifir($s, 'hatirlatma_ulasilamadi');

        $sonuc = match (true) {
            $yapildi && ($s['odenecek'] ?? null) === null && $ulasildi => $liste ? 'Alacak hatırlatma araması yapıldı.' : 'Hatırlatma araması yapıldı.',
            ($s['hatirlatma_aramasi_yapildi'] ?? null) === null => $liste ? 'Alacak hatırlatma araması yapılacak.' : 'Hatırlatma araması yapılacak.',
            self::bir($s, 'odenecek') => $liste ? 'Alacak zamanında ödenecek' : 'Zamanında Ödenecek',
            self::bir($s, 'asistan_guncelledi') => self::zincir($liste ? 'Planlanan ödeme tarihi asistan tarafından ' : 'Asistan tarafından ', self::tarih($s['planlanan_odeme_tarihi'] ?? null), ' olarak güncellendi.'),
            default => self::zincir($liste ? 'Alacak hatırlatması için ' : 'Ulaşılamadı.', self::saat($s['tekrar_arama_tarih_saat'] ?? null), ' saatinde tekrar arayacağım'),
        };

        $satir = [
            'mesaj'  => self::zincir(
                $s['musteri_ad'] ?? null, ' isimli ', $danisan ? 'danışanın ' : 'müşterinin ', self::tarih($s['planlanan_odeme_tarihi'] ?? null),
                ' tarihindeki ', $s['tutar'] ?? null, ' ₺ tutarındaki ', empty($s['taksitli_tahsilat_id']) ? 'senedinin' : 'taksitinin',
                ' ödeme hatırlatmasını', $yapildi ? ' yaptım.' : ' yapacağım.',
            ),
            'sonuc'  => $sonuc,
            'durum'  => self::durum($s, 'Ulaşıldı'),
            'baslik' => $liste ? ($s['musteri_ad'] ?? null) : 'Alacak Hatırlatması',
            'saat'   => self::saat($s['arama_saat'] ?? null),
        ];

        return self::tamamla($satir, $s, GorevTuru::Alacak, $g);
    }

    public static function kampanya(array $s, MobilGorevGorunumu $g): array
    {
        $arama = self::bir($s, 'arama_ile_gonderim');
        $sms = self::bir($s, 'sms_ile_gonderim');
        $kanal = match (true) {
            $arama && self::sifir($s, 'sms_ile_gonderim') => 'arama ',
            $sms && self::sifir($s, 'arama_ile_gonderim') => 'SMS ',
            default => ' arama ve SMS ',
        };
        $yapildi = self::bir($s, 'arama_yapildi') || self::bir($s, 'sms_gonderildi');

        $satir = [
            'mesaj'  => self::zincir($s['paket_isim'] ?? null, ' kampanya ', $kanal, ' ile tanıtımını ', $yapildi ? ' yaptım.' : ' yapacağım.'),
            'sonuc'  => null,
            'durum'  => null,
            'baslik' => 'Kampanya Tanıtımı',
            'saat'   => self::saat($s['asistan_tarih_saat'] ?? null),
        ];

        return self::tamamla($satir, $s, GorevTuru::Kampanya, $g);
    }

    /** Yalniz liste gorunumunde bulunur. */
    public static function musteri(array $s): array
    {
        $metin = self::zincir($s['musteri_ad'] ?? null, ' isimli müşteriyi ekledim.');

        return [
            'mesaj' => $metin, 'sonuc' => $metin, 'durum' => 'Müşteri işletmeye eklendi', 'baslik' => $s['musteri_ad'] ?? null,
            'saat' => self::saat($s['created_at'] ?? null), 'tur' => GorevTuru::Musteri->value,
            'iptal_edilebilir' => 0, 'iptal_edildi' => 0, 'randevu_id' => (int) $s['id'],
        ];
    }

    private static function tamamla(array $satir, array $s, GorevTuru $tur, MobilGorevGorunumu $g): array
    {
        $satir['randevu_id'] = (int) $s['id'];
        if ($g === MobilGorevGorunumu::Ozet) {
            return $satir;
        }

        return $satir + [
            'tur' => $tur->value,
            'iptal_edilebilir' => GorevSunumu::iptalEdilebilir($tur, $s) ? 1 : 0,
            'iptal_edildi' => GorevSunumu::iptalEdildi($tur, $s) ? 1 : 0,
        ];
    }

    private static function durum(array $s, string $ulasildi): string
    {
        $u = $s['hatirlatma_ulasilamadi'] ?? null;
        if ($u === null) {
            return '';
        }

        return match ((int) $u) {
            0 => $ulasildi,
            1 => 'Ulaşılamadı',
            default => '',
        };
    }

    /** SQL CONCAT: parcalardan biri null ise sonuc null. */
    private static function zincir(mixed ...$parcalar): ?string
    {
        foreach ($parcalar as $p) {
            if ($p === null) {
                return null;
            }
        }

        return implode('', array_map('strval', $parcalar));
    }

    private static function bir(array $s, string $k): bool
    {
        return ($s[$k] ?? null) !== null && (int) $s[$k] === 1;
    }

    private static function sifir(array $s, string $k): bool
    {
        return ($s[$k] ?? null) !== null && (int) $s[$k] === 0;
    }

    private static function tarih(mixed $deger): ?string
    {
        $t = $deger ? strtotime((string) $deger) : false;

        return $t ? date('d.m.Y', $t) : null;
    }

    private static function saat(mixed $deger): ?string
    {
        $t = $deger ? strtotime((string) $deger) : false;

        return $t ? date('H:i', $t) : null;
    }
}
