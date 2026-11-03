<?php

namespace App\Domain\Asistan;

/**
 * E-Asistan gorev satirini (ham DB kolonlari) ekran/uygulama kartina ceviren SAF
 * kurallar (DB yok, unit-test edilir). Metinler sunucuda TEK yerde uretilir —
 * web ve Flutter ayni cumleyi gosterir.
 *
 * Kart sozlesmesi:
 *   tur, id, <tur>_id (eski mobil EAsistan modeliyle uyum), baslik, musteri_ad,
 *   mesaj, sonuc, durum (eski mobil: 'Ulaşıldı'|'Ulaşılamadı'|''), durum_kod
 *   (bekliyor|ulasildi|ulasilamadi|iptal|bilgi), durum_etiket, saat ('H:i'|null),
 *   iptal_edilebilir, iptal_edildi
 *
 * Eski web easistandata'dan BILINCLI farklar (v2 duzeltmesi):
 *   - Alacak 'durum' tersti (ulasilamadi=1 iken "Ulaşıldı" yaziyordu).
 *   - Alacak "asistan guncelledi" olmayan kolon (planlanan_alacak_tarihi) okuyordu
 *     → 01.01.1970; planlanan_odeme_tarihi kullanilir.
 *   - Kampanya mesaji eziliyordu (yalniz "yapacağım."); olmayan kolon
 *     (sms_ile_gonderm) okunuyordu. Tam cumle + sms_ile_gonderim.
 *   - Taksit/senet disi alacak "senedinin" yaziyordu; "alacağının" yazilir.
 */
final class GorevSunumu
{
    public const ULASILDI = 'ulasildi';
    public const ULASILAMADI = 'ulasilamadi';
    public const BEKLIYOR = 'bekliyor';
    public const IPTAL = 'iptal';
    public const BILGI = 'bilgi';

    private const DURUM_ETIKET = [
        self::ULASILDI    => 'Ulaşıldı',
        self::ULASILAMADI => 'Ulaşılamadı',
        self::BEKLIYOR    => 'Bekliyor',
        self::IPTAL       => 'İptal Edildi',
        self::BILGI       => '',
    ];

    /** Eski mobil 'durum' alani yalniz arama sonucunu tasir (Flutter metinle karsilastirir). */
    private const ARAMA_SONUCLARI = [self::ULASILDI, self::ULASILAMADI];

    /** @param array<string,mixed> $s ham satir */
    public static function sun(GorevTuru $tur, array $s, bool $danisan): array
    {
        $kart = match ($tur) {
            GorevTuru::Randevu  => self::randevu($s, $danisan),
            GorevTuru::Alacak   => self::alacak($s, $danisan),
            GorevTuru::Kampanya => self::kampanya($s),
            GorevTuru::Musteri  => self::musteri($s, $danisan),
        };

        $iptalEdildi = self::iptalEdildi($tur, $s);
        $durumKod = $iptalEdildi ? self::IPTAL : $kart['durum_kod'];
        $id = (int) $s['id'];

        return [
            'tur'              => $tur->value,
            'id'               => $id,
            $tur->value.'_id'  => $id,
            'baslik'           => $tur->baslik(),
            'musteri_ad'       => isset($s['musteri_ad']) ? trim((string) $s['musteri_ad']) : null,
            'mesaj'            => $kart['mesaj'],
            'sonuc'            => $kart['sonuc'],
            'durum'            => in_array($kart['durum_kod'], self::ARAMA_SONUCLARI, true) ? self::DURUM_ETIKET[$kart['durum_kod']] : '',
            'durum_kod'        => $durumKod,
            'durum_etiket'     => self::DURUM_ETIKET[$durumKod],
            'saat'             => $kart['saat'],
            'iptal_edilebilir' => self::iptalEdilebilir($tur, $s),
            'iptal_edildi'     => $iptalEdildi,
        ];
    }

    /**
     * Gorev iptal edilebilir mi? Liste ve iptal ucu AYNI kurali kullanir.
     * Randevu/alacak (eski web kosulu sadelestirilmis): iptal edilmemis, arama ya
     * hic yapilmamis ya da ulasilamamis, ve tekrar arama henuz yapilmamis.
     * Kampanya (eski mobil kosulu): arama yapilmamis ve iptal edilmemis.
     */
    public static function iptalEdilebilir(GorevTuru $tur, array $s): bool
    {
        if ($tur->iptalKolonu() === null || self::iptalEdildi($tur, $s)) {
            return false;
        }
        if ($tur === GorevTuru::Kampanya) {
            return ! self::bir($s, 'arama_yapildi');
        }

        return (! self::bir($s, 'hatirlatma_aramasi_yapildi') || self::bir($s, 'hatirlatma_ulasilamadi'))
            && ! self::bir($s, 'tekrar_arandi');
    }

    public static function iptalEdildi(GorevTuru $tur, array $s): bool
    {
        $kolon = $tur->iptalKolonu();

        return $kolon !== null && self::bir($s, $kolon);
    }

    // ─────────────────────────── turler ───────────────────────────

    private static function randevu(array $s, bool $danisan): array
    {
        $yapildi = self::bir($s, 'hatirlatma_aramasi_yapildi');
        $tekrarSaat = self::saat($s['tekrar_arama_tarih_saat'] ?? null);

        $sonuc = match (true) {
            $yapildi && self::bir($s, 'randevuya_gelecek') => 'Randevuya gelecek.',
            ! $yapildi                                     => 'Hatırlatma araması yapılacak.',
            self::bir($s, 'hatirlatma_ulasilamadi') && ! self::bir($s, 'tekrar_arandi')
                => 'Ulaşılamadı. '.self::tekrarda($tekrarSaat, 'de').' arayacağım.',
            self::bir($s, 'asistan_guncelledi')
                => 'Asistan tarafından '.self::tarih($s['tarih'] ?? null).' '.self::saat($s['saat'] ?? null).' olarak güncellendi.',
            default => self::tekrarda($tekrarSaat, 'saatinde').' aradım, ulaşılamadı.',
        };

        return [
            'mesaj'     => self::kisi($s, $danisan).self::tarih($s['tarih'] ?? null).' randevu hatırlatmasını '.self::yapti($yapildi),
            'sonuc'     => $sonuc,
            'durum_kod' => self::aramaDurumu($s),
            // Arama yapilmadiysa randevu saati, yapildiysa arama saati (eski web).
            'saat'      => self::saat($yapildi ? ($s['arama_saat'] ?? null) : ($s['saat'] ?? null)),
        ];
    }

    private static function alacak(array $s, bool $danisan): array
    {
        $yapildi = self::bir($s, 'hatirlatma_aramasi_yapildi');
        $tekrarSaat = self::saat($s['tekrar_arama_tarih_saat'] ?? null);
        $nesne = match (true) {
            ! empty($s['taksitli_tahsilat_id']) => 'taksidinin',
            ! empty($s['senet_id'])             => 'senedinin',
            default                             => 'alacağının',
        };

        $sonuc = match (true) {
            $yapildi && ($s['odenecek'] ?? null) === null && ! self::bir($s, 'hatirlatma_ulasilamadi')
                => 'Hatırlatma araması yapıldı.',
            ! $yapildi                        => 'Hatırlatma araması yapılacak.',
            self::bir($s, 'odenecek')         => 'Zamanında ödenecek.',
            self::bir($s, 'asistan_guncelledi')
                => 'Asistan tarafından '.self::tarih($s['planlanan_odeme_tarihi'] ?? null).' olarak güncellendi.',
            ! self::bir($s, 'tekrar_arandi')  => 'Ulaşılamadı. '.self::tekrarda($tekrarSaat, 'saatinde').' arayacağım.',
            default                           => self::tekrarda($tekrarSaat, 'saatinde').' aradım, ulaşılamadı.',
        };

        return [
            'mesaj'     => self::kisi($s, $danisan).self::tarih($s['planlanan_odeme_tarihi'] ?? null).' tarihindeki '
                .number_format((float) ($s['tutar'] ?? 0), 2, ',', '.').' ₺ tutarındaki '.$nesne
                .' ödeme hatırlatmasını '.self::yapti($yapildi),
            'sonuc'     => $sonuc,
            'durum_kod' => self::aramaDurumu($s),
            'saat'      => self::saat($s['arama_saat'] ?? null),
        ];
    }

    private static function kampanya(array $s): array
    {
        $arama = self::bir($s, 'arama_ile_gonderim');
        $sms = self::bir($s, 'sms_ile_gonderim');
        $kanal = match (true) {
            $arama && ! $sms => 'arama',
            $sms && ! $arama => 'SMS',
            default          => 'arama ve SMS',
        };
        $yapildi = self::bir($s, 'arama_yapildi') || self::bir($s, 'sms_gonderildi');

        return [
            'mesaj'     => trim((string) ($s['paket_isim'] ?? '')).' kampanya '.$kanal.' ile tanıtımını '.self::yapti($yapildi),
            'sonuc'     => '',
            'durum_kod' => $yapildi ? self::BILGI : self::BEKLIYOR,
            'saat'      => self::saat($s['asistan_tarih_saat'] ?? null),
        ];
    }

    private static function musteri(array $s, bool $danisan): array
    {
        return [
            'mesaj'     => trim((string) ($s['musteri_ad'] ?? '')).' isimli '.($danisan ? 'danışanı' : 'müşteriyi').' ekledim.',
            'sonuc'     => ($danisan ? 'Danışan' : 'Müşteri').' işletmeye eklendi.',
            'durum_kod' => self::BILGI,
            'saat'      => self::saat($s['created_at'] ?? null),
        ];
    }

    // ─────────────────────────── yardimcilar ───────────────────────────

    /** hatirlatma_ulasilamadi: null → arama yok (bekliyor), 0 → ulasildi, 1 → ulasilamadi. */
    private static function aramaDurumu(array $s): string
    {
        $u = $s['hatirlatma_ulasilamadi'] ?? null;
        if ($u === null || $u === '') {
            return self::BEKLIYOR;
        }

        return (int) $u === 1 ? self::ULASILAMADI : self::ULASILDI;
    }

    private static function kisi(array $s, bool $danisan): string
    {
        return trim((string) ($s['musteri_ad'] ?? '')).' isimli '.($danisan ? 'danışanın ' : 'müşterinin ');
    }

    private static function yapti(bool $yapildi): string
    {
        return $yapildi ? 'yaptım.' : 'yapacağım.';
    }

    /** "14:30 saatinde tekrar" — saat bilinmiyorsa yalniz "Tekrar" (cumle basi). */
    private static function tekrarda(?string $saat, string $ek): string
    {
        return $saat ? "{$saat} {$ek} tekrar" : 'Tekrar';
    }

    private static function bir(array $s, string $k): bool
    {
        return (int) ($s[$k] ?? 0) === 1;
    }

    private static function tarih($deger): string
    {
        $t = $deger ? strtotime((string) $deger) : false;

        return $t ? date('d.m.Y', $t) : '';
    }

    private static function saat($deger): ?string
    {
        $t = $deger ? strtotime((string) $deger) : false;

        return $t ? date('H:i', $t) : null;
    }
}
