<?php

namespace App\Domain\Musteri;

/**
 * Musteri formlarinin sabit secenekleri — TEK KAYNAK. UI'da sabit liste YOK:
 * web ve Flutter bunlari GET /musteri/form-verisi'nden alir.
 * Degerler eski musteri-ekle-modal / saglik formu / Flutter Referans ile ayni.
 */
final class MusteriSozluk
{
    /** musteri_portfoy.musteri_tipi (eski adi "referans") */
    public const REFERANSLAR = [1 => 'İnternet', 2 => 'Reklam', 3 => 'Instagram', 4 => 'Facebook', 5 => 'Tanıdık'];

    public const CINSIYETLER = [0 => 'Kadın', 1 => 'Erkek'];

    public const CILT_TIPLERI = [0 => 'Karma', 1 => 'Yağlı', 2 => 'Hassas', 3 => 'Kuru', 4 => 'Nemli', 5 => 'Normal'];

    /** users tablosundaki evet/hayir saglik sorulari: kolon => soru (eski form sirasi). */
    public const SAGLIK_SORULARI = [
        'hemofili_hastaligi_var'           => 'Hemofili hastalığı var mı?',
        'seker_hastaligi_var'              => 'Şeker hastalığı var mı?',
        'hamile'                           => 'Hamile mi?',
        'yakin_zamanda_ameliyat_gecirildi' => 'Yakın bir zamanda ameliyat geçirdi mi?',
        'alerji_var'                       => 'Herhangi bir alerjisi var mı?',
        'alkol_alimi_yapildi'              => '48 saat içinde alkol alımı var mı?',
        'regl_doneminde'                   => 'Regl döneminde mi?',
        'deri_yumusak_doku_hastaligi_var'  => 'Deri veya yumuşak doku hastalığı var mı?',
        'surekli_kullanilan_ilac_Var'      => 'Sürekli kullandığı ilaç var mı?',
        'kemoterapi_goruyor'               => 'Kemoterapi görüyor mu?',
        'daha_once_uygulama_yaptirildi'    => 'Daha önce uygulama yaptırdı mı?',
    ];

    /** Evet cevabinin aciklama kolonu (eski kayitta hic yazilmiyordu — v2 yazar). */
    public const SAGLIK_ACIKLAMALARI = [
        'surekli_kullanilan_ilac_Var'   => 'surekli_kullanilan_ilac_aciklama',
        'daha_once_uygulama_yaptirildi' => 'daha_once_yaptirilan_uygulama_aciklama',
    ];

    /** Serbest metin saglik alanlari. */
    public const SAGLIK_METINLERI = ['ek_saglik_sorunu', 'surekli_kullanilan_ilac_aciklama', 'daha_once_yaptirilan_uygulama_aciklama'];

    public static function referansAdi(mixed $id): ?string
    {
        return self::REFERANSLAR[(int) $id] ?? null;
    }

    public static function cinsiyetAdi(mixed $id): ?string
    {
        return $id === null || $id === '' ? null : (self::CINSIYETLER[(int) $id] ?? null);
    }

    /** [{id, ad}] — secim listesi bicimi (form-verisi). */
    public static function secenekler(array $harita): array
    {
        return array_map(fn ($id, $ad) => ['id' => $id, 'ad' => $ad], array_keys($harita), $harita);
    }
}
