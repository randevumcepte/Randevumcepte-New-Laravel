<?php

namespace App\Services\Rapor;

use App\Domain\Rapor\RaporDonemi;
use App\Domain\Satis\KalemTuru;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Satis kalemi toplama sorgulari — Satis Raporlari, Isletme Raporlari ve personel prim
 * hesabinin ORTAK veri kaynagi (her rapor kendi SUM/JOIN'ini yazmaz).
 *
 * Kanonik kurallar (kullanici karari, 3 Kas 2026):
 *   - Tutar NET   : fiyat − indirim_tutari (v2 AdisyonHesap / Satis Takibi ile ayni; fiyat satir toplamidir)
 *   - Donem       : SATIS TARIHI = adisyonlar.tarih; bos/sifir ise DATE(created_at)
 *                   (gecmise donuk girilen satis kendi tarihine duser — mobil kural)
 *   - Seansli     : randevusuz + seans_sayisi>0 hizmet kalemi paket satisidir (KalemTuru::seansliSql)
 *   - Odenen      : kalemin kalem-tahsilat toplami (donemden bagimsiz; "donemde yapilan
 *                   satislarin tahsil durumu" — eski raporlarla ayni anlam)
 *   - Adet        : urunde SUM(adet), hizmet/pakette satir sayisi
 * Hicbir sey YAZMAZ.
 */
class SatisKalemSorgusu
{
    /** Satis tarihi ifadesi (Y-m-d) — $a: adisyonlar alias'i. */
    public static function satisTarihiSql(string $a = 'a'): string
    {
        return "COALESCE(NULLIF(DATE({$a}.tarih),'0000-00-00'), DATE({$a}.created_at))";
    }

    /**
     * Adisyonu satis tarihine gore donemle sinirlar — indeks dostu (salon_id, tarih):
     * tarih dolu ise dogrudan aralik, bos/sifir ise created_at araligi.
     */
    public static function donemFiltresi(Builder $q, RaporDonemi $d, string $a = 'a'): Builder
    {
        return $q->where(function ($w) use ($d, $a) {
            $w->whereBetween("{$a}.tarih", [$d->baslangic, $d->bitSaat()])
                ->orWhere(function ($x) use ($d, $a) {
                    $x->where(fn ($y) => $y->whereNull("{$a}.tarih")->orWhere("{$a}.tarih", '0000-00-00'))
                        ->whereBetween("{$a}.created_at", [$d->basSaat(), $d->bitSaat()]);
                });
        });
    }

    /**
     * Kalem satirlari (kalem basina bir satir): id, adisyon_id, user_id, personel_id,
     * kalem_id (katalog), seansli, adet, brut, indirim, net, odenen, satis_tarihi.
     */
    public function satirlar(int $salonId, RaporDonemi $d, KalemTuru $tur, ?int $personelId = null): Builder
    {
        $t = $tur->kalemTablosu();
        $adet = $tur === KalemTuru::Urun ? 'COALESCE(k.adet,1)' : '1';
        $seansli = $tur === KalemTuru::Hizmet ? KalemTuru::seansliSql('k') : '0';

        $q = DB::table($t.' as k')
            ->join('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
            ->where('a.salon_id', $salonId)
            ->when($personelId, fn ($q) => $q->where('k.personel_id', $personelId))
            ->selectRaw(implode(', ', [
                'k.id', 'k.adisyon_id', 'a.user_id', 'k.personel_id',
                'k.'.$tur->katalogFk().' as kalem_id',
                "CAST({$seansli} AS UNSIGNED) as seansli",
                "{$adet} as adet",
                'COALESCE(k.fiyat,0) as brut',
                'COALESCE(k.indirim_tutari,0) as indirim',
                '(COALESCE(k.fiyat,0) - COALESCE(k.indirim_tutari,0)) as net',
                '(SELECT COALESCE(SUM(th.tutar),0) FROM '.$tur->tahsilatTablosu().' th WHERE th.'.$tur->tahsilatFk().' = k.id) as odenen',
                self::satisTarihiSql('a').' as satis_tarihi',
            ]));

        return self::donemFiltresi($q, $d);
    }

    /**
     * Katalog kalemi bazli toplamlar (tum turler): tur, kalem_id, seansli, ad, adet, satir,
     * brut, indirim, net, odenen. Hizmet seansli/normal ayri satirdir.
     *
     * @return array<int, array<string,mixed>>
     */
    public function kalemBazli(int $salonId, RaporDonemi $d, ?int $personelId = null): array
    {
        $sonuc = [];
        foreach (KalemTuru::cases() as $tur) {
            $gruplar = DB::query()->fromSub($this->satirlar($salonId, $d, $tur, $personelId), 's')
                ->groupBy('s.kalem_id', 's.seansli')
                ->selectRaw('s.kalem_id, s.seansli, SUM(s.adet) as adet, COUNT(*) as satir, SUM(s.brut) as brut, SUM(s.indirim) as indirim, SUM(s.net) as net, SUM(s.odenen) as odenen')
                ->get();
            $adlar = $this->adlar($tur, $gruplar->pluck('kalem_id')->filter()->unique()->all());
            foreach ($gruplar as $g) {
                $sonuc[] = [
                    'tur'      => $tur->value,
                    'kalem_id' => (int) $g->kalem_id,
                    'seansli'  => (bool) $g->seansli,
                    'ad'       => $adlar[(int) $g->kalem_id] ?? 'Silinmiş '.$tur->etiket(),
                ] + self::sayilar($g);
            }
        }

        return $sonuc;
    }

    /**
     * Personel + katalog kalemi bazli toplamlar (prim ve personel performansi icin):
     * personel_id, tur, kalem_id, seansli, adet, satir, net, odenen. personel_id NULL elenir.
     *
     * @return array<int, array<string,mixed>>
     */
    public function personelKalemBazli(int $salonId, RaporDonemi $d): array
    {
        $sonuc = [];
        foreach (KalemTuru::cases() as $tur) {
            $gruplar = DB::query()->fromSub($this->satirlar($salonId, $d, $tur), 's')
                ->whereNotNull('s.personel_id')
                ->groupBy('s.personel_id', 's.kalem_id', 's.seansli')
                ->selectRaw('s.personel_id, s.kalem_id, s.seansli, SUM(s.adet) as adet, COUNT(*) as satir, SUM(s.brut) as brut, SUM(s.indirim) as indirim, SUM(s.net) as net, SUM(s.odenen) as odenen')
                ->get();
            foreach ($gruplar as $g) {
                $sonuc[] = [
                    'personel_id' => (int) $g->personel_id,
                    'tur'         => $tur->value,
                    'kalem_id'    => (int) $g->kalem_id,
                    'seansli'     => (bool) $g->seansli,
                ] + self::sayilar($g);
            }
        }

        return $sonuc;
    }

    /** Katalog adlari (tek sorgu): kalem_id => ad. */
    public function adlar(KalemTuru $tur, array $idler): array
    {
        if (! $idler) {
            return [];
        }

        return DB::table($tur->katalogTablosu())->whereIn('id', $idler)->pluck($tur->adKolonu(), 'id')
            ->map(fn ($ad) => trim((string) $ad))->all();
    }

    private static function sayilar(object $g): array
    {
        return [
            'adet'    => (int) $g->adet,
            'satir'   => (int) $g->satir,
            'brut'    => round((float) $g->brut, 2),
            'indirim' => round((float) $g->indirim, 2),
            'net'     => round((float) $g->net, 2),
            'odenen'  => round((float) $g->odenen, 2),
        ];
    }
}
