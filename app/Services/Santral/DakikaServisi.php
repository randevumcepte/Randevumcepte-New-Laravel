<?php

namespace App\Services\Santral;

use App\Domain\Santral\SantralErisim;
use Illuminate\Support\Facades\DB;

/**
 * Giden konusma dakikasi (havuz) ozeti — eski App\SistemYonetim\DakikaHesap::hesapla
 * ile ayni hesap. Havuz = tanimli dakika − sayim baslangicindan bugune harcanan.
 * Sayim baslangici: paket ozel tarihi > salon olusturma tarihi > 2020-01-01.
 */
class DakikaServisi
{
    public function __construct(private SantralIstemcisi $istemci) {}

    /**
     * Yonetici → salon geneli (tanimli/kullanilan/kalan/yuzde).
     * Personel → yalniz kendi dahilisinin giden dakikasi (havuz alanlari null);
     * dahilisi yoksa null (eski santral sayfasi karti hic gostermiyordu).
     */
    public function ozet(int $salonId, SantralErisim $erisim): ?array
    {
        $dahili = $erisim->dakikaDahilisi();
        if (! $erisim->yonetici && $dahili === null) {
            return null;
        }

        $trunk = (string) DB::table('sabit_numaralar')->where('salon_id', $salonId)->value('numara');
        $paket = DB::table('salon_dakika_paketi')->where('salon_id', $salonId)->first(['tanimli_dakika', 'sayim_baslangic']);
        $baslangic = $this->sayimBaslangic($salonId, $paket);

        $kullanim = preg_match('/^\d+$/', $trunk)
            ? $this->istemci->dakikaOzeti($trunk, $baslangic, date('Y-m-d'), $dahili)
            : ['dakika' => 0.0, 'adet' => 0, 'hata' => false];

        $kullanilan = round($kullanim['dakika'], 1);
        $sonuc = [
            'kapsam'          => $erisim->yonetici ? 'salon' : 'dahili',
            'dahili'          => $dahili,
            'kullanilan'      => $kullanilan,
            'adet'            => $kullanim['adet'],
            'sayim_baslangic' => $baslangic,
            'hata'            => $kullanim['hata'],
            'tanimli'         => null,
            'kalan'           => null,
            'yuzde'           => null,
        ];
        if ($erisim->yonetici) {
            $tanimli = (int) ($paket->tanimli_dakika ?? 0);
            $sonuc['tanimli'] = $tanimli;
            $sonuc['kalan'] = round($tanimli - $kullanilan, 1);
            $sonuc['yuzde'] = $tanimli > 0 ? (int) min(100, round($kullanilan / $tanimli * 100)) : 0;
        }

        return $sonuc;
    }

    private function sayimBaslangic(int $salonId, ?object $paket): string
    {
        if ($paket && ! empty($paket->sayim_baslangic)) {
            return date('Y-m-d', strtotime((string) $paket->sayim_baslangic));
        }
        $olusturma = DB::table('salonlar')->where('id', $salonId)->value('created_at');

        return $olusturma ? date('Y-m-d', strtotime((string) $olusturma)) : '2020-01-01';
    }
}
