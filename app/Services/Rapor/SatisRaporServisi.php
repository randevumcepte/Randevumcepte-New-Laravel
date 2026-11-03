<?php

namespace App\Services\Rapor;

use App\Domain\Rapor\PrimHesap;
use App\Domain\Rapor\RaporDonemi;
use App\Domain\Satis\KalemTuru;
use App\Models\Personeller;
use App\Models\PersonelPrimOrani;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use App\Support\YerelListe;
use Illuminate\Support\Facades\DB;

/**
 * Satis Raporlari (eski isletmeyonetim/raporlar: Tumu / Hizmet / Urun / Paket / Personel
 * sekmeleri + "Hizmeti Alan Musteriler") ve mobil v1 *Raporlari uclarinin TEK v2 karsiligi.
 *
 * Veri SatisKalemSorgusu'ndan (net tutar, satis tarihi, seansli→paket). Eski kodun
 * duzeltilen farklari: brut yerine net (indirim hayalet alacak yaratmaz), urun adedi
 * SUM(adet), personel primi Prim & Hak Edis formulu (PrimHesap), urun/paket musteri
 * listesi de calisir, ciro_kar_gor maskesi SUNUCUDA (tablo hucreleri dahil).
 * Hicbir sey YAZMAZ.
 */
class SatisRaporServisi
{
    /** Kalem satiri turleri: seansli hizmet ayri tur ('seans') olarak paket sekmesindedir. */
    public const SEKME_TURLERI = [
        'tum'    => ['hizmet', 'urun', 'paket', 'seans'],
        'hizmet' => ['hizmet'],
        'urun'   => ['urun'],
        'paket'  => ['paket', 'seans'],
    ];

    public const SIRALANABILIR = ['ad', 'tur_ad', 'adet', 'gelir', 'indirim', 'kazanc', 'alacak', 'toplam_maliyet', 'kar'];

    public const PERSONEL_SIRALANABILIR = ['personel_adi', 'hizmet_geliri', 'hizmet_primi', 'urun_geliri', 'urun_primi', 'paket_geliri', 'paket_primi', 'toplam_gelir', 'toplam_prim'];

    public const MUSTERI_SIRALANABILIR = ['satis_tarihi', 'musteri', 'personel', 'gelir', 'odenen', 'kalan'];

    public function __construct(private SatisKalemSorgusu $sorgu) {}

    /**
     * Kalem bazli satis raporu + ozet kartlari.
     *
     * @param  array  $o  ListeIstegi::dizi()
     */
    public function kalemler(int $salonId, RaporDonemi $d, string $sekme, ?int $personelId, array $o, bool $maskeli): array
    {
        $turler = self::SEKME_TURLERI[$sekme];
        $maliyet = in_array('hizmet', $turler, true) ? $this->hizmetMaliyetleri($salonId) : [];

        $satirlar = [];
        foreach ($this->sorgu->kalemBazli($salonId, $d, $personelId) as $g) {
            $tur = $g['seansli'] ? 'seans' : $g['tur'];
            if (in_array($tur, $turler, true)) {
                $satirlar[] = $this->satir($tur, $g, $maliyet[$g['kalem_id']] ?? null);
            }
        }

        $ozet = $this->ozet($satirlar, $sekme === 'hizmet');
        $liste = YerelListe::uygula($satirlar, $o, ['ad', 'tur_ad']);
        if ($maskeli) {
            $ozet = self::maskele($ozet);
            $liste['data'] = array_map([self::class, 'maskele'], $liste['data']);
        }

        return $liste + ['ozet' => $ozet, 'maskeli' => $maskeli, 'donem' => $d->dizi()];
    }

    /**
     * Personel bazli gelir (tahsil edilen) + prim — Prim & Hak Edis formulu (PrimHesap).
     * Yalniz aktif + arsivsiz personel; hareketsiz personel listelenmez.
     */
    public function personeller(int $salonId, RaporDonemi $d, array $o): array
    {
        $personeller = Personeller::where('salon_id', $salonId)->where('aktif', 1)
            ->where(fn ($q) => $q->where('arsivli', 0)->orWhereNull('arsivli'))
            ->get(['id', 'personel_adi', 'hizmet_prim_yuzde', 'urun_prim_yuzde', 'paket_prim_yuzde',
                'hizmet_prim_detayli', 'urun_prim_detayli', 'paket_prim_detayli'])
            ->keyBy('id');

        $primler = PrimHesap::hesapla(
            $this->sorgu->personelKalemBazli($salonId, $d),
            $personeller->map(fn ($p) => $p->toArray())->all(),
            PersonelPrimOrani::harita($salonId),
        );

        $satirlar = [];
        foreach ($primler as $pid => $p) {
            if ($p['toplam_gelir'] == 0 && $p['toplam_prim'] == 0) {
                continue;
            }
            $satirlar[] = ['personel_id' => $pid, 'personel_adi' => trim((string) $personeller[$pid]->personel_adi)] + $p;
        }

        $ozet = ['toplam_gelir' => 0.0, 'toplam_prim' => 0.0];
        foreach ($satirlar as $s) {
            $ozet['toplam_gelir'] = round($ozet['toplam_gelir'] + $s['toplam_gelir'], 2);
            $ozet['toplam_prim'] = round($ozet['toplam_prim'] + $s['toplam_prim'], 2);
        }

        return YerelListe::uygula($satirlar, $o, ['personel_adi']) + ['ozet' => $ozet, 'donem' => $d->dizi()];
    }

    /**
     * Bir katalog kalemini alan musteriler (kalem satiri basina) — eski hizmetiAlanMusteriler;
     * urun/paket/seans icin de calisir (eski urun/paket butonlari bozuktu). SQL'de sayfali.
     *
     * @param  string  $tur  hizmet | urun | paket | seans
     */
    public function musteriler(int $salonId, RaporDonemi $d, string $tur, int $kalemId, ?int $personelId, array $o, bool $telGor, bool $maskeli): array
    {
        $kalemTuru = $tur === 'seans' ? KalemTuru::Hizmet : KalemTuru::from($tur);
        $satirlar = $this->sorgu->satirlar($salonId, $d, $kalemTuru, $personelId)
            ->where('k.'.$kalemTuru->katalogFk(), $kalemId);
        if ($kalemTuru === KalemTuru::Hizmet) {
            $satirlar->whereRaw(($tur === 'seans' ? '' : 'NOT ').KalemTuru::seansliSql('k'));
        }

        $q = DB::query()->fromSub($satirlar, 's')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 's.personel_id')
            ->when(ListeIstegi::like($o['ara'] ?? null), fn ($q, $like) => $q->where(fn ($w) => $w
                ->where('u.name', 'like', $like)->orWhere('p.personel_adi', 'like', $like)->orWhere('s.adisyon_id', 'like', $like)));

        $toplamlar = (clone $q)->selectRaw('COUNT(*) as adet, COALESCE(SUM(s.net),0) as gelir, COALESCE(SUM(s.odenen),0) as odenen')->first();
        $siralama = ['satis_tarihi' => 's.satis_tarihi', 'musteri' => 'u.name', 'personel' => 'p.personel_adi',
            'gelir' => 's.net', 'odenen' => 's.odenen', 'kalan' => DB::raw('(s.net - s.odenen)')];

        $data = $q->orderBy($siralama[$o['sirala'] ?? 'satis_tarihi'] ?? 's.satis_tarihi', $o['yon'] ?? 'desc')
            ->orderByDesc('s.id')
            ->forPage($o['page'], $o['per_page'])
            ->get(['s.id', 's.adisyon_id', 's.satis_tarihi', 's.net', 's.odenen', 'u.name as musteri', 'u.cep_telefon', 'p.personel_adi as personel'])
            ->map(function ($r) use ($telGor, $maskeli) {
                $satir = [
                    'kalem_id'     => (int) $r->id,
                    'adisyon_id'   => (int) $r->adisyon_id,
                    'satis_tarihi' => $r->satis_tarihi,
                    'musteri'      => $r->musteri ?: '-',
                    'telefon'      => $r->cep_telefon ? ($telGor ? $r->cep_telefon : YetkiSabitleri::telefonMaskele($r->cep_telefon)) : null,
                    'personel'     => $r->personel ?: null,
                    'gelir'        => round((float) $r->net, 2),
                    'odenen'       => round((float) $r->odenen, 2),
                    'kalan'        => round((float) $r->net - (float) $r->odenen, 2),
                ];

                return $maskeli ? self::maskele($satir) : $satir;
            })->all();

        $ozet = [
            'adet'   => (int) $toplamlar->adet,
            'gelir'  => round((float) $toplamlar->gelir, 2),
            'odenen' => round((float) $toplamlar->odenen, 2),
            'kalan'  => round((float) $toplamlar->gelir - (float) $toplamlar->odenen, 2),
        ];

        return ['data' => $data, 'ozet' => $maskeli ? self::maskele($ozet) : $ozet, 'maskeli' => $maskeli]
            + ListeIstegi::meta($o['page'], $o['per_page'], (int) $toplamlar->adet);
    }

    private function satir(string $tur, array $g, ?float $birimMaliyet): array
    {
        $alacak = round($g['net'] - $g['odenen'], 2);
        $satir = [
            'tur'     => $tur,
            'tur_ad'  => $tur === 'seans' ? 'Paket (Seanslı)' : KalemTuru::from($tur)->etiket(),
            'kalem_id' => $g['kalem_id'],
            'ad'      => $g['ad'].($tur === 'seans' ? ' (Seanslı)' : ''),
            'adet'    => $g['adet'],
            'brut'    => $g['brut'],
            'indirim' => $g['indirim'],
            'gelir'   => $g['net'],
            'kazanc'  => $g['odenen'],
            'alacak'  => $alacak,
            'birim_maliyet'  => null,
            'toplam_maliyet' => null,
            'kar'            => null,
        ];
        // Maliyet/kar yalniz normal hizmette (salon_sunulan_hizmetler.maliyet). Kar = tahsil
        // edilen − maliyet (eski raporla ayni: kar tahsilata gore).
        if ($tur === 'hizmet') {
            $satir['birim_maliyet'] = round((float) $birimMaliyet, 2);
            $satir['toplam_maliyet'] = round((float) $birimMaliyet * $g['adet'], 2);
            $satir['kar'] = round($g['odenen'] - $satir['toplam_maliyet'], 2);
        }

        return $satir;
    }

    private function ozet(array $satirlar, bool $maliyetli): array
    {
        $o = ['gelir' => 0.0, 'indirim' => 0.0, 'kazanc' => 0.0, 'alacak' => 0.0, 'adet' => 0, 'cesit' => count($satirlar),
            'toplam_maliyet' => null, 'kar' => null];
        foreach ($satirlar as $s) {
            foreach (['gelir', 'indirim', 'kazanc', 'alacak'] as $k) {
                $o[$k] = round($o[$k] + $s[$k], 2);
            }
            $o['adet'] += $s['adet'];
            if ($maliyetli) {
                $o['toplam_maliyet'] = round(($o['toplam_maliyet'] ?? 0) + (float) $s['toplam_maliyet'], 2);
                $o['kar'] = round(($o['kar'] ?? 0) + (float) $s['kar'], 2);
            }
        }

        return $o;
    }

    /** Maskede gizlenen alanlar: tahsil edilen + ondan turetilebilenler (alacak/kalan = gelir − tahsil, kar). */
    public const MASKELI_ALANLAR = ['kazanc', 'odenen', 'kar', 'alacak', 'kalan'];

    /** rapor.ciro_kar_gor yoksa tahsil edilen ve ondan turetilebilen alanlar gizlenir — sunucuda. */
    private static function maskele(array $s): array
    {
        foreach (self::MASKELI_ALANLAR as $k) {
            if (array_key_exists($k, $s)) {
                $s[$k] = null;
            }
        }

        return $s;
    }

    /** hizmet_id => birim maliyet (salon_sunulan_hizmetler.maliyet; kolon yoksa bos). */
    private function hizmetMaliyetleri(int $salonId): array
    {
        try {
            return DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->whereNotNull('maliyet')
                ->pluck('maliyet', 'hizmet_id')->map(fn ($m) => (float) $m)->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
