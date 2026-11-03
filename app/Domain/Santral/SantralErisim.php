<?php

namespace App\Domain\Santral;

/**
 * Giris yapan kullanicinin bir salonun santral verisi uzerindeki erisim kapsami
 * (deger nesnesi; SantralErisimServisi uretir). Kurallar web + mobil icin TEK yerde:
 *
 *   - Yonetici (rol 5 degil): salonun tum cagrilari + salon geneli dakika + ayarlar.
 *   - Personel (rol 5)    : yalnizca KENDI dahilisinin (dahili_no / dahili_no_webrtc)
 *                           gelen/giden cagrilari + hicbir dahiliye dusmeyen sonucsuz
 *                           (ana-menu) cagrilar; dakika kendi dahilisi icin; ayar yok.
 *                           Dahilisi tanimsiz personel yalniz sonucsuz cagrilari gorur
 *                           (fail-closed; eski mobil filtresiz tum listeyi donduruyordu).
 */
final class SantralErisim
{
    /**
     * @param array<int,string> $kendiDahilileri Kullanicinin bu salondaki dahilileri (bos olabilir)
     */
    public function __construct(
        public readonly bool $yonetici,
        public readonly array $kendiDahilileri,
        public readonly bool $telefonGor,
    ) {}

    /** Siniflandirilmis CDR satiri bu kullaniciya gosterilir mi? */
    public function gorebilirMi(array $sinif): bool
    {
        if ($this->yonetici || $sinif['durum'] === '4') {
            return true;
        }
        $karsi = (string) preg_replace('/\D/', '', (string) $sinif['karsi_numara']);

        return in_array($sinif['personel_dahili'], $this->kendiDahilileri, true)
            || ($karsi !== '' && in_array($karsi, $this->kendiDahilileri, true));
    }

    /** Dakika ozeti icin daraltilacak dahili: yonetici → null (salon geneli), personel → birincil dahili. */
    public function dakikaDahilisi(): ?string
    {
        return $this->yonetici ? null : ($this->kendiDahilileri[0] ?? null);
    }

    /** Istemcinin (web/Flutter) ekran kurgusu icin yayinlanan bayraklar. */
    public function dizi(): array
    {
        return [
            'yonetici'      => $this->yonetici,
            'ayar_yonet'    => $this->yonetici,
            'telefon_gor'   => $this->telefonGor,
            'kendi_dahili'  => $this->kendiDahilileri[0] ?? null,
        ];
    }
}
