<?php

namespace App\Domain\SistemYonetim;

/** Talep olaylarinin yan etkileri: hangi alan ne zaman damgalanir. Ilk damga korunur, sonraki olay ezmez. */
final class TalepGecisi
{
    /** Yanit: ilk yanit damgalanir, acik talep isleme alinir. */
    public static function yanit(string $durum, ?string $ilkYanit, string $simdi): array
    {
        $degisim = $ilkYanit ? [] : ['ilk_yanit_tarihi' => $simdi];
        if ($durum === TalepDurumu::Acik->value) {
            $degisim['durum'] = TalepDurumu::Islemde->value;
        }

        return $degisim;
    }

    public static function durum(TalepDurumu $yeni, ?string $cozumlenme, ?string $kapanis, string $simdi): array
    {
        $degisim = ['durum' => $yeni->value];
        if ($yeni === TalepDurumu::Cozumlendi && ! $cozumlenme) {
            $degisim['cozumlenme_tarihi'] = $simdi;
        }
        if ($yeni === TalepDurumu::Kapali && ! $kapanis) {
            $degisim['kapanis_tarihi'] = $simdi;
        }

        return $degisim;
    }
}
