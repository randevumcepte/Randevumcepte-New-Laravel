<?php

namespace App\Domain\Kampanya;

/**
 * SENARYO SİHİRBAZI kuralları (saf) — eski StoreAdminController::senaryoPresetleri /
 * senaryoOnizle / kampanyaIceriginiGoruntule("senaryo-") BİREBİR.
 *
 * Konuşma DALLANMALARI santral AGI'de sabittir (evet/hayır → kod SMS → randevu); salon yalnız
 * adım METİNLERİNİ ve hangi AKSİYONLARIN açık olduğunu seçer. Arama dışı kanallarda yalnız
 * 'acilis' kullanılır.
 */
final class Senaryo
{
    /** Adım anahtarları → sihirbaz etiketi (sıra = konuşma sırası). */
    public const ADIMLAR = [
        'acilis'        => 'Açılış',
        'soru_kod'      => 'İndirim kodu sorusu',
        'onay_kod'      => 'Kod gönderildi onayı',
        'soru_randevu'  => 'Randevu sorusu',
        'soru_gun_saat' => 'Gün / saat sorusu',
        'onay_randevu'  => 'Randevu onayı',
        'kapanis'       => 'Kapanış',
        'red'           => 'Ret cevabı',
    ];

    public const AKSIYONLAR = [
        'indirim_kodu_sms' => 'İndirim kodunu SMS / WhatsApp ile gönder',
        'yol_tarifi_sms'   => 'Yol tarifini gönder',
        'randevu_olustur'  => 'Görüşmede randevu oluştur',
    ];

    public const TIPLER = ['geri_kazanim', 'dogum_gunu', 'paket_bitti', 'yeni_hizmet', 'ozel'];

    /** Önizlemede örnek değerler (eski senaryoOnizle varsayılanları). */
    public const ORNEK = ['indirim' => '20', 'gun' => 7, 'hizmet' => 'Cilt Bakımı'];

    /** Hazır senaryolar (DB'de değil; salon birini seçip kendi kopyasını kaydeder). */
    public static function presetler(): array
    {
        $giris = 'İyi günler {müşteri}, sizi {işletmeden} adına arıyorum. ';
        $gunSaat = 'Hangi gün ve saat olsun?';
        $onbes = 'Randevunuzu oluşturdum. On beş dakika önce gelmenizi rica ederim.';
        $saglik = 'Sağlıklı günler dilerim.';

        $p = fn (string $ad, string $emoji, string $aciklama, array $adimlar, array $aks) => [
            'ad' => $ad, 'emoji' => $emoji, 'aciklama' => $aciklama, 'gorev_turu' => Kanal::Arama->value,
            'adimlar' => $adimlar + ['soru_gun_saat' => $gunSaat, 'kapanis' => $saglik],
            'aksiyonlar' => array_combine(array_keys(self::AKSIYONLAR), $aks),
        ];

        return [
            'geri_kazanim' => $p('Geri Kazanım', '😴', 'Uzun süredir gelmeyen müşteriyi indirim + randevu ile geri kazan', [
                'acilis'       => $giris.'Yaklaşık {gün} gündür sizi aramızda göremiyoruz. Sizi bir kahve içmeye davet etmek istiyoruz. Ayrıca gelirken kullanabilmeniz için size özel bir indirim kuponu hazırladık.',
                'soru_kod'     => 'Bu indirim kodunuzu ve yol tarifimizi mesaj olarak göndermemi ister misiniz?',
                'onay_kod'     => 'Mesaj olarak indirim kodunuzu ve yol tarifini gönderdim.',
                'soru_randevu' => 'Dilerseniz hemen bir randevu da oluşturabilirim, ister misiniz?',
                'onay_randevu' => $onbes,
                'red'          => 'Tabi, anlıyorum. İleride ihtiyaç duyarsanız biz her zaman buradayız. '.$saglik,
            ], [true, true, true]),
            'dogum_gunu' => $p('Doğum Günü', '🎂', 'Doğum günü kutlaması + hediye indirim', [
                'acilis'       => $giris.'Doğum gününüzü en içten dileklerimizle kutlarız. Bu özel gününüze özel size bir hediye indirimimiz var.',
                'soru_kod'     => 'Hediye indirim kodunuzu mesaj olarak göndermemi ister misiniz?',
                'onay_kod'     => 'Hediye indirim kodunuzu mesaj olarak gönderdim.',
                'soru_randevu' => 'Dilerseniz size uygun bir randevu oluşturabilirim, ister misiniz?',
                'onay_randevu' => 'Randevunuzu oluşturdum.',
                'kapanis'      => 'Nice mutlu yıllar dileriz, sağlıklı günler.',
                'red'          => 'Tabi efendim. Doğum gününüzü tekrar kutlar, sağlıklı günler dileriz.',
            ], [true, false, true]),
            'paket_bitti' => $p('Paket / Seans Bitti', '📦', 'Paketi biten müşteriye yenileme teklifi', [
                'acilis'       => $giris.'{ürün_hizmet_paket} paketinizdeki seanslarınız tamamlandı. Sonuçlarınızın kalıcı olması için yenileme döneminiz geldi.',
                'soru_kod'     => 'Yenileme için size özel indirimimizi mesaj olarak göndermemi ister misiniz?',
                'onay_kod'     => 'İndirim kodunuzu mesaj olarak gönderdim.',
                'soru_randevu' => 'Hemen bir randevu oluşturmamı ister misiniz?',
                'onay_randevu' => $onbes,
                'red'          => 'Tabi, anlıyorum. Hazır olduğunuzda biz buradayız. '.$saglik,
            ], [true, false, true]),
            'yeni_hizmet' => $p('Yeni Hizmet Tanıtımı', '✨', 'Yeni açılan hizmeti tanıt, ilk deneme indirimi ver', [
                'acilis'       => $giris.'{ürün_hizmet_paket} hizmetimiz yeni açıldı ve ilk deneyenlere özel bir indirimimiz var.',
                'soru_kod'     => 'İndirim kodunuzu ve yol tarifimizi mesaj olarak göndermemi ister misiniz?',
                'onay_kod'     => 'İndirim kodunuzu ve yol tarifini mesaj olarak gönderdim.',
                'soru_randevu' => 'Denemek için bir randevu oluşturmamı ister misiniz?',
                'onay_randevu' => $onbes,
                'red'          => 'Tabi efendim. Merak ederseniz her zaman bekleriz. '.$saglik,
            ], [true, true, true]),
            'ozel' => $p('Sıfırdan Oluştur', '✏️', 'Kendi metinlerinizi baştan yazın', [
                'acilis'       => trim($giris),
                'soru_kod'     => '', 'onay_kod' => '', 'soru_randevu' => '',
                'onay_randevu' => $onbes,
                'red'          => 'Tabi efendim, sağlıklı günler dilerim.',
            ], [false, false, false]),
        ];
    }

    /** Yalnız bilinen adım/aksiyon anahtarları (fazlası atılır; eksik adım ''). */
    public static function adimlariTemizle(array $adimlar): array
    {
        $t = [];
        foreach (array_keys(self::ADIMLAR) as $k) {
            $t[$k] = mb_substr(trim((string) ($adimlar[$k] ?? '')), 0, 1000);
        }

        return $t;
    }

    public static function aksiyonlariTemizle(array $aks): array
    {
        $t = [];
        foreach (array_keys(self::AKSIYONLAR) as $k) {
            $t[$k] = filter_var($aks[$k] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $t;
    }

    /** Kampanya mesaj şablonu olarak kullanılan metin (eski: aramada açılış + kod sorusu). */
    public static function sablonMetni(array $adimlar, int $gorevTuru): string
    {
        return $gorevTuru === Kanal::Arama->value
            ? trim(($adimlar['acilis'] ?? '').' '.($adimlar['soru_kod'] ?? ''))
            : (string) ($adimlar['acilis'] ?? '');
    }

    /**
     * Önizleme diyaloğu (eski senaryoOnizle BİREBİR; müşteri cevapları sabit örnek).
     *
     * @param  callable(string):string  $coz  yer tutucu çözücü
     * @return array{diyalog: array<int, array{kim:string, metin:string}>, tek_metin:string}
     */
    public static function onizle(array $adimlar, array $aks, int $gorevTuru, callable $coz): array
    {
        $acilis = $coz((string) ($adimlar['acilis'] ?? ''));
        if ($gorevTuru !== Kanal::Arama->value) {
            return ['diyalog' => [['kim' => 'bot', 'metin' => $acilis]], 'tek_metin' => $acilis];
        }

        $d = [];
        $ekle = function (string $kim, ?string $metin) use (&$d) {
            $metin = trim((string) $metin);
            if ($metin !== '') {
                $d[] = ['kim' => $kim, 'metin' => $metin];
            }
        };

        $ekle('bot', $acilis);
        if ((! empty($aks['indirim_kodu_sms']) || ! empty($aks['yol_tarifi_sms'])) && ! empty($adimlar['soru_kod'])) {
            $ekle('bot', $coz($adimlar['soru_kod']));
            $ekle('musteri', 'Evet, olur.');
            $ekle('bot', $coz((string) ($adimlar['onay_kod'] ?? '')));
        }
        if (! empty($aks['randevu_olustur']) && ! empty($adimlar['soru_randevu'])) {
            $ekle('bot', $coz($adimlar['soru_randevu']));
            $ekle('musteri', 'Evet.');
            $ekle('bot', $coz((string) ($adimlar['soru_gun_saat'] ?? 'Hangi gün ve saat olsun?')));
            $ekle('musteri', 'Çarşamba öğleden sonra olsun.');
            $ekle('bot', 'Memnuniyetle, hemen kontrol ediyorum. Çarşamba saat 13:00 uygun mu efendim?');
            $ekle('musteri', 'Evet.');
            $ekle('bot', $coz((string) ($adimlar['onay_randevu'] ?? '')));
        }
        $ekle('bot', $coz((string) ($adimlar['kapanis'] ?? '')));

        return ['diyalog' => $d, 'tek_metin' => trim($acilis.' '.$coz((string) ($adimlar['soru_kod'] ?? '')))];
    }
}
