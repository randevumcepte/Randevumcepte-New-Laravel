<?php

namespace App\Domain\Rapor;

/**
 * Mobil kasa hesabi (SAF) — eski ApiController kasaraporu / gunSonuRaporu / devredenAylar formulu BIREBIR.
 * Web kasasi (KasaRaporServisi, GunSonuServisi) bunu KULLANMAZ; onun yuvarlamasi ve kapsami ayridir.
 *
 * Kural: tutarlar YUVARLANMAZ; faturasiz gizlemede tahsilatin faturali adisyon kalemlerine dusen kismi sayilir;
 * tahsilat listesi harita anahtarlariyla suzulur (0 tutarli kalem tahsilati da satir getirir).
 */
final class MobilKasaHesabi
{
    private const KOVA_BASLANGIC = 0;

    /**
     * @param  iterable<array{tahsilat_id:int|string, tutar:mixed}>  $kalemTahsilatlari  faturali adisyonlarin kalem tahsilatlari
     * @return array<int,float> tahsilat_id => faturali kisim
     */
    public static function faturaliKisimlar(iterable $kalemTahsilatlari): array
    {
        $harita = [];
        foreach ($kalemTahsilatlari as $s) {
            $s = (array) $s;
            $harita[$s['tahsilat_id']] = ($harita[$s['tahsilat_id']] ?? 0) + (float) $s['tutar'];
        }

        return $harita;
    }

    /** Faturasiz gizlemede donem geliri: yalniz listedeki tahsilatlarin faturali kismi. */
    public static function faturaliGelir(iterable $tahsilatIdleri, array $harita): float|int
    {
        $toplam = 0;
        foreach ($tahsilatIdleri as $id) {
            $toplam += (float) ($harita[$id] ?? 0);
        }

        return $toplam;
    }

    public static function ozet(mixed $donemGelir, mixed $donemGider, mixed $tumGelir, mixed $tumGider): array
    {
        return [
            'toplamgelir' => $donemGelir,
            'toplamgider' => $donemGider,
            'toplamKasa'  => $donemGelir - $donemGider,
            'toplamCiro'  => $tumGelir - $tumGider,
        ];
    }

    /**
     * Gun sonu ozeti + tam detay kalemleri (personel cirosu ve en cok satanlar servisten gelir).
     *
     * @param  iterable<array>  $tahsilatlar  id, user_id, odeme_tarihi, odeme_yontemi_id, para_girisi, tutar, musteri, tahsil_eden, yontem
     * @param  iterable<array>  $masraflar  tutar, odeme_yontemi_id, aciklama, kategori, harcayan_id, harcayan, yontem, personel_gideri, personel_maas_odemesi_id
     * @param  array<int,float>  $faturaliKisim  faturaliKisimlar() haritasi; yalniz $faturasizGizle iken okunur
     */
    public static function gunSonu(iterable $tahsilatlar, iterable $masraflar, bool $faturasizGizle, array $faturaliKisim): array
    {
        $gelir = self::sifirKova();
        $islemSay = 0;
        $gercekGelir = 0;
        $musteriler = [];
        $satislar = [];
        foreach ($tahsilatlar as $t) {
            $t = (array) $t;
            $tutar = $faturasizGizle ? (float) ($faturaliKisim[$t['id']] ?? 0) : (float) $t['tutar'];
            $gelir[self::kova($t['odeme_yontemi_id'])] += $tutar;
            if (! $t['para_girisi']) {
                $islemSay++;
                $gercekGelir += $tutar;
                if ($t['user_id']) {
                    $musteriler[$t['user_id']] = true;
                }
            }
            $satislar[] = [
                'saat'        => date('H:i', strtotime((string) $t['odeme_tarihi'])),
                'musteri'     => $t['para_girisi'] ? 'Kasa Girişi' : ($t['musteri'] ?? '-'),
                'tahsil_eden' => $t['tahsil_eden'] ?? '',
                'yontem'      => $t['yontem'] ?? '',
                'para_girisi' => (bool) $t['para_girisi'],
                'tutar'       => $tutar,
            ];
        }

        $masraf = self::sifirKova();
        $isletme = [];
        $personel = [];
        $isletmeToplam = 0;
        $personelToplam = 0;
        foreach ($masraflar as $m) {
            $m = (array) $m;
            $tutar = (float) $m['tutar'];
            $masraf[self::kova($m['odeme_yontemi_id'])] += $tutar;
            $aciklama = $m['aciklama'];
            if (trim((string) $aciklama) === '' && ($m['kategori'] ?? null) !== null) {
                $aciklama = $m['kategori'];
            }
            $satir = [
                'harcayan' => $m['harcayan_id'] ? ($m['harcayan'] ?? '') : 'Kasa',
                'aciklama' => $aciklama,
                'yontem'   => $m['yontem'] ?? '',
                'tutar'    => $tutar,
            ];
            if ($m['personel_gideri'] || $m['personel_maas_odemesi_id']) {
                $satir['tip'] = $m['personel_gideri'] ? 'Personel Gideri' : 'Personel Ödemesi';
                $personel[] = $satir;
                $personelToplam += $tutar;
            } else {
                $isletme[] = $satir;
                $isletmeToplam += $tutar;
            }
        }

        $net = [];
        foreach ($gelir as $k => $v) {
            $net[$k] = $v - $masraf[$k];
        }
        $gelirToplam = array_sum($gelir);
        $masrafToplam = array_sum($masraf);

        return [
            'gelir'                 => $gelir,
            'gelir_toplam'          => $gelirToplam,
            'masraf'                => $masraf,
            'masraf_toplam'         => $masrafToplam,
            'net'                   => $net,
            'net_toplam'            => $gelirToplam - $masrafToplam,
            'islem_say'             => $islemSay,
            'musteri_say'           => count($musteriler),
            'ort_sepet'             => $islemSay > 0 ? ($gercekGelir / $islemSay) : 0,
            'satislar'              => $satislar,
            'isletme_masraflari'    => $isletme,
            'personel_giderleri'    => $personel,
            'isletme_masraf_toplam' => $isletmeToplam,
            'personel_gider_toplam' => $personelToplam,
        ];
    }

    /** @return array{0:string,1:string} ayin ilk ve son gunu (Y-m-d) — karsilastirma saatsizdir, eskiyle ayni */
    public static function ayAraligi(int $yil, int $ay): array
    {
        $bas = $yil.'-'.str_pad((string) $ay, 2, '0', STR_PAD_LEFT).'-01';

        return [$bas, date('Y-m-t', strtotime($bas))];
    }

    public static function aySatiri(int $ay, string $ayAdi, int $yil, mixed $tahsilatlar, mixed $masraflar): array
    {
        return [
            'ay'            => $ay,
            'ay_adi'        => $ayAdi,
            'yil'           => $yil,
            'tahsilatlar'   => $tahsilatlar,
            'masraflar'     => $masraflar,
            'donem_net_kar' => $tahsilatlar - $masraflar,
        ];
    }

    private static function kova(mixed $odemeYontemiId): string
    {
        return OdemeKovasi::icin((int) $odemeYontemiId)->value;
    }

    /** @return array<string,int> */
    private static function sifirKova(): array
    {
        return array_map(fn () => self::KOVA_BASLANGIC, OdemeKovasi::sifir());
    }
}
