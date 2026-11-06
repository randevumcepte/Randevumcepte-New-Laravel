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
    private const ART_ARDA_HATA_SINIRI = 3;

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

        $paket = $this->paket($salonId);
        $baslangic = $this->sayimBaslangic($salonId, $paket);
        $kullanim = $this->kullanim($salonId, $baslangic, $dahili);

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
            $sonuc = array_merge($sonuc, $this->havuz((int) ($paket->tanimli_dakika ?? 0), $kullanilan));
        }

        return $sonuc;
    }

    /**
     * Salon havuzunda kalan dakika (tanimli − kullanilan). Softphone header rozeti: eski layout
     * DakikaHesap::hesapla()['kalan']'i rolden bagimsiz gosteriyordu. Santral okunamazsa null.
     */
    public function havuzKalan(int $salonId): ?float
    {
        $paket = $this->paket($salonId);
        $kullanim = $this->kullanim($salonId, $this->sayimBaslangic($salonId, $paket), null);
        if ($kullanim['hata']) {
            return null;
        }

        return round((int) ($paket->tanimli_dakika ?? 0) - round($kullanim['dakika'], 1), 1);
    }

    /**
     * Sistem yonetimi listesi: salon basina TEK santral istegi (onbellekli), paket/trunk/olusturma toplu okunur.
     * Santral art arda cevap vermezse kalan salonlar icin istek atilmaz (hata=true): sayfa N x zaman asimi beklemez.
     *
     * @param  list<int>  $salonIdler
     * @return array<int, array{trunk:?string,tanimli:int,kullanilan:float,kalan:float,yuzde:int,adet:int,sayim_baslangic:string,hata:bool}>
     */
    public function yonetimOzetleri(array $salonIdler): array
    {
        $paketler = DB::table('salon_dakika_paketi')->whereIn('salon_id', $salonIdler)->get(['salon_id', 'tanimli_dakika', 'sayim_baslangic'])->keyBy('salon_id');
        $olusturmalar = DB::table('salonlar')->whereIn('id', $salonIdler)->pluck('created_at', 'id');
        // Azalan sira + pluck: salonun ilk kaydi kazanir (tekil cagridaki value('numara') ile ayni).
        $trunklar = DB::table('sabit_numaralar')->whereIn('salon_id', $salonIdler)->orderByDesc('id')->pluck('numara', 'salon_id');

        $sonuc = [];
        $artArdaHata = 0;
        foreach ($salonIdler as $salonId) {
            $paket = $paketler[$salonId] ?? null;
            $trunk = $trunklar[$salonId] ?? null;
            $baslangic = $this->baslangicTarihi($paket, $olusturmalar[$salonId] ?? null);
            $kullanim = $artArdaHata >= self::ART_ARDA_HATA_SINIRI
                ? ['dakika' => 0.0, 'adet' => 0, 'hata' => true]
                : $this->trunkKullanim((string) $trunk, $baslangic, null);
            $artArdaHata = $kullanim['hata'] ? $artArdaHata + 1 : 0;

            $kullanilan = round($kullanim['dakika'], 1);
            $sonuc[$salonId] = [
                'trunk'           => $trunk,
                'tanimli'         => (int) ($paket->tanimli_dakika ?? 0),
                'kullanilan'      => $kullanilan,
                'adet'            => $kullanim['adet'],
                'sayim_baslangic' => $baslangic,
                'hata'            => $kullanim['hata'],
            ] + $this->havuz((int) ($paket->tanimli_dakika ?? 0), $kullanilan);
        }

        return $sonuc;
    }

    /** @return array{tanimli:int,kalan:float,yuzde:int} */
    private function havuz(int $tanimli, float $kullanilan): array
    {
        return [
            'tanimli' => $tanimli,
            'kalan'   => round($tanimli - $kullanilan, 1),
            'yuzde'   => $tanimli > 0 ? (int) min(100, round($kullanilan / $tanimli * 100)) : 0,
        ];
    }

    private function paket(int $salonId): ?object
    {
        return DB::table('salon_dakika_paketi')->where('salon_id', $salonId)->first(['tanimli_dakika', 'sayim_baslangic']);
    }

    /** @return array{dakika: float, adet: int, hata: bool} Trunk tanimsizsa kullanim 0. */
    private function kullanim(int $salonId, string $baslangic, ?string $dahili): array
    {
        return $this->trunkKullanim((string) DB::table('sabit_numaralar')->where('salon_id', $salonId)->value('numara'), $baslangic, $dahili);
    }

    /** @return array{dakika: float, adet: int, hata: bool} */
    private function trunkKullanim(string $trunk, string $baslangic, ?string $dahili): array
    {
        return preg_match('/^\d+$/', $trunk)
            ? $this->istemci->dakikaOzeti($trunk, $baslangic, date('Y-m-d'), $dahili)
            : ['dakika' => 0.0, 'adet' => 0, 'hata' => false];
    }

    private function sayimBaslangic(int $salonId, ?object $paket): string
    {
        if ($paket && ! empty($paket->sayim_baslangic)) {
            return $this->baslangicTarihi($paket, null);
        }

        return $this->baslangicTarihi(null, DB::table('salonlar')->where('id', $salonId)->value('created_at'));
    }

    private function baslangicTarihi(?object $paket, ?string $olusturma): string
    {
        if ($paket && ! empty($paket->sayim_baslangic)) {
            return date('Y-m-d', strtotime((string) $paket->sayim_baslangic));
        }

        return $olusturma ? date('Y-m-d', strtotime($olusturma)) : '2020-01-01';
    }
}
