<?php

namespace App\Services\Seans;

use App\Domain\Seans\LazerTespiti;
use App\Domain\Seans\SeansDurumu;
use App\Domain\Seans\SeansKalemTuru;
use App\Domain\Seans\SeansSayaci;
use App\Models\Salonlar;
use App\Services\Paket\PaketKullanimi;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Seans takibi OKUMA — eski web "Seans Takibi" (StoreAdminController::seans_getir) ve
 * mobil v1 /seanslar uclarinin TEK karsiligi.
 *
 * Satir = seansli bir satis kalemi (paket satisi ya da seansli tek hizmet); detayi
 * (hizmet/bolge kartlari + seans daireleri) ayni cevapta gelir, istemci ikinci istek
 * atmaz. Sayfa disi kayitlar icin hicbir detay sorgusu calismaz; sayfadakiler TOPLU
 * yuklenir (eski web satir basina ~10 sorgu yapiyordu).
 *
 * Eski uclardan BILINCLI farklar:
 *   - Musteri yalniz {id, ad} doner (v1 tum users satirini donuyordu — KVKK).
 *   - Arama telefonla da calisir ama telefon cevapta yok.
 *   - Paket toplami web kuraliyla (seans_sayisi × hizmet sayisi); v1 mobil yalniz seans_sayisi.
 */
class SeansTakipServisi
{
    /** ListeIstegi sirala alani → birlesik sorgu kolonu. */
    public const SIRALANABILIR = ['tarih' => 'olusturma', 'musteri' => 'musteri_adi', 'ad' => 'ad', 'baslangic' => 'baslangic'];

    public function __construct(
        private SeansKapsam $kapsam,
        private SeansCihazServisi $cihaz,
        private PaketKullanimi $kullanim,
    ) {}

    public function liste(int $salonId, ListeIstegi $l, ?int $musteriId = null): array
    {
        $birlesik = $this->kalemSorgusu(SeansKalemTuru::PAKET, $salonId, $musteriId, $l->likeDeseni())
            ->unionAll($this->kalemSorgusu(SeansKalemTuru::HIZMET, $salonId, $musteriId, $l->likeDeseni()));

        $toplam = DB::query()->fromSub($birlesik, 'x')->count();
        $satirlar = DB::query()->fromSub($birlesik, 'x')
            ->orderBy(self::SIRALANABILIR[$l->sirala] ?? 'olusturma', $l->yon)
            ->orderBy('id', 'desc')
            ->forPage($l->sayfa, $l->sayfaBoyu)
            ->get();

        return ['data' => $this->detaylandir($salonId, $satirlar->all())]
            + ListeIstegi::meta($l->sayfa, $l->sayfaBoyu, $toplam)
            + [
                'durumlar' => SeansDurumu::sozluk(),
                'ayarlar'  => ['grup_dersi_aktif' => Salonlar::grupDersiAktif($salonId)],
            ];
    }

    /** Tek kalemin guncel satiri (yazma sonrasi istemci yalniz o satiri tazeler). */
    public function kalem(int $salonId, SeansKalemTuru $tur, int $kalemId): ?array
    {
        $satir = $this->kalemSorgusu($tur, $salonId, null, null)->where('k.id', $kalemId)->first();

        return $satir ? $this->detaylandir($salonId, [$satir])[0] : null;
    }

    /** Bir turun seansli kalemleri (paket ve hizmet ayni kolon setiyle → UNION). */
    private function kalemSorgusu(SeansKalemTuru $tur, int $salonId, ?int $musteriId, ?string $like): Builder
    {
        $paket = $tur === SeansKalemTuru::PAKET;
        $adKolonu = $paket ? 'c.paket_adi' : 'c.hizmet_adi';

        return DB::table($tur->tablo().' as k')
            ->join('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin($paket ? 'paketler as c' : 'hizmetler as c', 'c.id', '=', $paket ? 'k.paket_id' : 'k.hizmet_id')
            ->where('a.salon_id', $salonId)
            // seans_sayisi eski kayitlarda NULL olabilir; seans satiri varsa yine listelenir (eski web ile ayni).
            ->where(fn ($w) => $w->where('k.seans_sayisi', '>', 0)->orWhereExists(
                fn ($s) => $s->select(DB::raw(1))->from('adisyon_paket_seanslar as s')->whereColumn('s.'.$tur->seansKolonu(), 'k.id'),
            ))
            ->when($musteriId, fn ($w) => $w->where('a.user_id', $musteriId))
            ->when($like, fn ($w) => $w->where(fn ($x) => $x->where('u.name', 'like', $like)
                ->orWhere('u.cep_telefon', 'like', $like)
                ->orWhere($adKolonu, 'like', $like)))
            ->select([
                DB::raw("'{$tur->value}' as tur"), 'k.id', 'k.adisyon_id', 'k.seans_sayisi',
                DB::raw(($paket ? 'k.paket_id' : 'k.hizmet_id').' as katalog_id'),
                DB::raw(($paket ? 'k.baslangic_tarihi' : 'k.islem_tarihi').' as baslangic'),
                'a.created_at as olusturma', 'a.user_id as musteri_id', 'u.name as musteri_adi',
                DB::raw($adKolonu.' as ad'),
            ]);
    }

    /** Sayfadaki satirlara hizmet kartlari + seanslar + sayaclar (TOPLU sorgularla). */
    private function detaylandir(int $salonId, array $satirlar): array
    {
        if (! $satirlar) {
            return [];
        }
        $kalemIdler = ['paket' => [], 'hizmet' => []];
        $katalog = ['paket' => [], 'hizmet' => []];
        foreach ($satirlar as $s) {
            $kalemIdler[$s->tur][] = (int) $s->id;
            $katalog[$s->tur][] = (int) $s->katalog_id;
        }

        $seanslar = [];
        $tumu = DB::table('adisyon_paket_seanslar')
            ->where(fn ($w) => $w->whereIn('adisyon_paket_id', $kalemIdler['paket'])->orWhereIn('adisyon_hizmet_id', $kalemIdler['hizmet']))
            ->orderBy('seans_tarih')->orderBy('seans_saat')->orderBy('id')
            ->get(['id', 'adisyon_paket_id', 'adisyon_hizmet_id', 'hizmet_id', 'seans_no', 'seans_tarih', 'seans_saat', 'geldi']);
        foreach ($tumu as $s) {
            $tur = SeansKalemTuru::seanstan($s);
            $seanslar[$tur->value.':'.$tur->seansKalemId($s)][] = $s;
        }
        $hizmetler = $this->kapsam->kalemHizmetleri($katalog['paket'], $katalog['hizmet']);
        $cihazli = $this->cihaz->veriOlanSeanslar($tumu->pluck('id')->all());
        $gecerlilik = $this->kullanim->durumlar($kalemIdler['paket']);

        return array_map(function ($s) use ($salonId, $seanslar, $hizmetler, $cihazli, $gecerlilik) {
            $tur = SeansKalemTuru::from($s->tur);
            $kalemSeanslari = $seanslar[$s->tur.':'.$s->id] ?? [];
            $gecerli = $tur === SeansKalemTuru::PAKET ? ($gecerlilik[(int) $s->id] ?? null) : null;
            $kartlar = $this->kartlar($tur, $s, $hizmetler[$s->tur][(int) $s->katalog_id] ?? [], $kalemSeanslari, $gecerli);
            $lazer = LazerTespiti::adlardanBiri($s->ad) || in_array(true, array_column($kartlar, 'lazer'), true);
            $dokum = $lazer && array_filter($kalemSeanslari, fn ($x) => isset($cihazli[(int) $x->id]));

            return [
                'tur'              => $s->tur,
                'id'               => (int) $s->id,
                'anahtar'          => $s->tur.'_'.$s->id,
                'adisyon_id'       => (int) $s->adisyon_id,
                'musteri'          => ['id' => (int) $s->musteri_id, 'ad' => (string) $s->musteri_adi],
                'ad'               => (string) $s->ad,
                'baslangic_tarihi' => self::gecerliTarih($s->baslangic),
                'sinirsiz'         => $gecerli['sinirsiz'] ?? false,
                'gecerlilik'       => $gecerli && $gecerli['sure'] !== '' ? ['sure' => $gecerli['sure'], 'baslangic' => $gecerli['baslangic'], 'bitis' => $gecerli['bitis'], 'suresi_doldu' => $gecerli['suresi_doldu']] : null,
                'olusturma'        => $s->olusturma,
                'sayac'            => SeansSayaci::hesapla(
                    SeansSayaci::kalemToplami((int) $s->seans_sayisi, $tur === SeansKalemTuru::PAKET ? count($kartlar) : 1),
                    array_column($kalemSeanslari, 'geldi'),
                ),
                'lazer'            => $lazer,
                'dokum_url'        => $dokum ? $this->cihaz->dokumUrl($tur, (int) $s->id, $salonId) : null,
                'hizmetler'        => $kartlar,
            ];
        }, $satirlar);
    }

    /**
     * Hizmet (bolge) kartlari: paket → paketteki her hizmet; tek hizmet → kendisi
     * (tek hizmette kalemin TUM seanslari karta ait — eski web getHizmetDetaylari).
     *
     * @param  array<int,string>  $hizmetAdlari
     */
    private function kartlar(SeansKalemTuru $tur, object $s, array $hizmetAdlari, array $seanslar, ?array $gecerli = null): array
    {
        $toplam = max(0, (int) $s->seans_sayisi);
        $kartlar = [];
        foreach ($hizmetAdlari as $hizmetId => $ad) {
            $kart = $tur === SeansKalemTuru::PAKET
                ? array_values(array_filter($seanslar, fn ($x) => (int) $x->hizmet_id === $hizmetId))
                : $seanslar;
            $kartlar[] = [
                'hizmet_id' => $hizmetId,
                'ad'        => $ad,
                'lazer'     => LazerTespiti::adlardanBiri($s->ad, $ad),
                'sayac'     => SeansSayaci::hesapla($toplam, array_column($kart, 'geldi')),
                // Sinirsiz: toplam teknik tavan; yuzlerce bos daire yerine tek "yeni kullanim" dairesi.
                'bos_hak'   => ($gecerli['sinirsiz'] ?? false) ? (($gecerli['suresi_doldu'] ?? false) ? 0 : 1) : SeansSayaci::bosHak($toplam, count($kart)),
                'seanslar'  => array_map([self::class, 'seansDto'], $kart),
            ];
        }

        return $kartlar;
    }

    public static function seansDto(object $s): array
    {
        $durum = SeansDurumu::geldidenCoz($s->geldi);

        return [
            'id'       => (int) $s->id,
            'seans_no' => $s->seans_no !== null ? (int) $s->seans_no : null,
            'tarih'    => self::gecerliTarih($s->seans_tarih),
            'saat'     => $s->seans_saat ? substr((string) $s->seans_saat, 0, 5) : null,
            'geldi'    => $durum->geldi(),
            'durum'    => $durum->value,
        ];
    }

    /** '0000-00-00' / bos / bozuk tarih → null; aksi halde Y-m-d. */
    private static function gecerliTarih(?string $t): ?string
    {
        $t = substr((string) $t, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && ! str_starts_with($t, '0000') ? $t : null;
    }
}
