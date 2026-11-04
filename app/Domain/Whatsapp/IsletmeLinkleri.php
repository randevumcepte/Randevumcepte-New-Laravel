<?php

namespace App\Domain\Whatsapp;

/**
 * Müşteriye mesaj yazarken tek tıkla eklenen işletme bağlantıları (salonlar kolonları).
 * Cevap alanları v1 musteri-whatsapp-durum / whatsapp-ayar-kaydet ile aynı; boş başlık varsayılana düşer.
 */
final class IsletmeLinkleri
{
    /** @var list<string> */
    public const LINK_ALANLARI = ['konum_linki', 'instagram_linki', 'web_linki'];

    /** @var list<string> */
    public const BASLIK_ALANLARI = ['instagram_baslik', 'web_baslik'];

    public const LINK_UZUNLUK = 500;

    public const BASLIK_UZUNLUK = 60;

    /** @param object $salon salonlar satırı */
    public static function sun(object $salon): array
    {
        return [
            'konum_linki'      => (string) ($salon->konum_linki ?? ''),
            'instagram_linki'  => (string) ($salon->instagram_linki ?? ''),
            'web_linki'        => (string) ($salon->web_linki ?? ''),
            'konum_baslik'     => 'Konumumuz',
            'instagram_baslik' => trim((string) ($salon->instagram_baslik ?? '')) ?: 'Instagram',
            'web_baslik'       => trim((string) ($salon->web_baslik ?? '')) ?: 'Web Sitesi',
        ];
    }

    /**
     * Doğrulanmış girdi → kolon güncellemesi. Yalnız gönderilen alanlar yazılır (kısmi kayıt);
     * boş değer NULL olur.
     *
     * @param  array<string,mixed>  $girdi
     * @return array<string,?string>
     */
    public static function guncelleme(array $girdi): array
    {
        $g = [];
        foreach ([...self::LINK_ALANLARI, ...self::BASLIK_ALANLARI] as $alan) {
            if (array_key_exists($alan, $girdi)) {
                $deger = trim((string) $girdi[$alan]);
                $g[$alan] = $deger === '' ? null : $deger;
            }
        }

        return $g;
    }
}
