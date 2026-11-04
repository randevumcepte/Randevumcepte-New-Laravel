<?php

namespace App\Services\Musteri;

use App\Domain\Musteri\DogumTarihi;
use App\Domain\Musteri\MusteriSozluk;
use App\Domain\Musteri\Segment;
use App\Exceptions\BulunamadiException;
use App\Models\Salonlar;
use App\Support\ListeIstegi;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Musteri SALT-OKUMA servisi — liste ve detayin ORTAK tabani (tek sorgu + tek DTO).
 *
 * Musteri = users satiri; salona baglanti = musteri_portfoy (aktif=1). Ayni kisi birden
 * cok salonun portfoyunde olabilir: salona ozel alanlar (notlar, referans, kara liste,
 * kayit tarihi) PORTFOYDEN, kimlik alanlari users'tan okunur.
 *
 * DTO, Flutter MusteriDanisan.fromJson alanlarinin UST-KUMESIDIR (gecis gunu drop-in):
 * id, name, cep_telefon, email, dogum_tarihi, cinsiyet, tc_kimlik_no, musteri_tipi
 * (= referans id), ozel_notlar, kayit_tarihi, randevu_sayisi, son_randevu_tarihi,
 * odeme_sayisi, profil_resim, saglik alanlari...
 */
class MusteriOkumaServisi
{
    public function __construct(private PortfoyKapsami $kapsam) {}

    /** Salonun aktif portfoyu ⋈ users (+ opsiyonel personel kapsami). */
    public function temelSorgu(int $salonId, ?int $kisitPersonel = null): Builder
    {
        $q = DB::table('musteri_portfoy as mp')
            ->join('users as u', 'u.id', '=', 'mp.user_id')
            ->where('mp.salon_id', $salonId)
            ->where('mp.aktif', 1);

        return $this->kapsam->uygula($q, $salonId, $kisitPersonel);
    }

    /** Salondaki musteri basina tahsilat sayisi + toplami (segment + "toplam alinan ucret"). */
    public function tahsilatOzetiSorgusu(int $salonId): Builder
    {
        return DB::table('tahsilatlar')->where('salon_id', $salonId)->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as odeme_sayisi, COALESCE(SUM(tutar),0) as toplam_odenen');
    }

    /**
     * Musterinin bu salonun aktif portfoyunde (ve kullanicinin kapsaminda) oldugunu dogrular.
     * Degilse 404 — baska isletmenin musterisinin varligi sizdirilmaz.
     */
    public function portfoyZorunlu(int $salonId, int $userId, ?int $kisitPersonel = null): object
    {
        return $this->temelSorgu($salonId, $kisitPersonel)->where('mp.user_id', $userId)
            ->first(['mp.id', 'mp.user_id', 'mp.kara_liste', 'mp.musteri_tipi', 'mp.ozel_notlar', 'mp.created_at'])
            ?? throw new BulunamadiException('Müşteri bulunamadı');
    }

    /**
     * Detay DTO'su (+ saglik alanlari $saglikGor ise).
     *
     * @param  array{telefon:bool, saglik:bool}  $gor
     */
    public function detay(int $salonId, int $userId, ?int $kisitPersonel, array $gor): array
    {
        $saglik = array_values(array_filter(
            [...array_keys(MusteriSozluk::SAGLIK_SORULARI), ...MusteriSozluk::SAGLIK_METINLERI, 'cilt_tipi'],
            fn ($k) => Schema::hasColumn('users', $k),
        ));

        $row = $this->satirSorgusu($salonId, $kisitPersonel)->where('mp.user_id', $userId)
            ->addSelect(array_map(fn ($k) => 'u.'.$k, ['tc_kimlik_no', 'dogum_tarihi', 'cinsiyet', 'adres', 'meslek', 'il_id', ...$saglik]))
            ->first() ?? throw new BulunamadiException('Müşteri bulunamadı');

        $randevu =$this->randevuOzetleri($salonId, [$userId])[$userId] ?? null;
        $dto = $this->donustur($row, $randevu, $gor['telefon']);

        $dogum = DogumTarihi::parcalar($row->dogum_tarihi);
        $dto += [
            'tc_kimlik_no'      => $row->tc_kimlik_no ? (string) $row->tc_kimlik_no : null,
            'dogum_tarihi'      => $dogum['gun'] ? $row->dogum_tarihi : null,
            'dogum_tarihi_parca' => $dogum,
            'cinsiyet'          => $row->cinsiyet !== null ? (int) $row->cinsiyet : null,
            'cinsiyet_adi'      => MusteriSozluk::cinsiyetAdi($row->cinsiyet),
            'adres'             => $row->adres,
            'meslek'            => $row->meslek,
            'il_id'             => $row->il_id !== null ? (int) $row->il_id : null,
        ];

        if ($gor['saglik']) {
            $dto['saglik'] = [];
            foreach ($saglik as $k) {
                $v = $row->{$k};
                $dto['saglik'][$k] = in_array($k, MusteriSozluk::SAGLIK_METINLERI, true)
                    ? $v
                    : ($v === null ? null : (int) $v);
            }
        }

        return $dto;
    }

    /**
     * Liste + detayin ortak satir sorgusu (kimlik + portfoy + tahsilat ozeti).
     */
    public function satirSorgusu(int $salonId, ?int $kisitPersonel = null): Builder
    {
        return $this->temelSorgu($salonId, $kisitPersonel)
            ->leftJoinSub($this->tahsilatOzetiSorgusu($salonId), 't', 't.user_id', '=', 'mp.user_id')
            ->select([
                'u.id', 'u.name', 'u.cep_telefon', 'u.email', 'u.profil_resim',
                'mp.id as portfoy_id', 'mp.created_at as kayit', 'mp.kara_liste', 'mp.musteri_tipi', 'mp.ozel_notlar',
                DB::raw('COALESCE(t.odeme_sayisi,0) as odeme_sayisi'),
                DB::raw('COALESCE(t.toplam_odenen,0) as toplam_odenen'),
            ]);
    }

    /**
     * Sayfadaki musteriler icin randevu sayisi + son randevu tarihi (N+1 yok; eski liste ile ayni).
     *
     * @param  int[]  $userIdler
     * @return array<int, object{randevu_sayisi:int, son_randevu_tarihi:?string}>
     */
    public function randevuOzetleri(int $salonId, array $userIdler): array
    {
        if (! $userIdler) {
            return [];
        }

        return DB::table('randevular')->where('salon_id', $salonId)->whereIn('user_id', $userIdler)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as randevu_sayisi, MAX(tarih) as son_randevu_tarihi')
            ->get()->keyBy('user_id')->all();
    }

    /** Ham satir → kararli DTO (liste ve detay ayni alanlari tasir). */
    public function donustur(object $row, ?object $randevu, bool $telGor): array
    {
        $odemeSayisi = (int) $row->odeme_sayisi;

        return [
            'id'                 => (int) $row->id,
            'portfoy_id'         => (int) $row->portfoy_id,
            'name'               => $row->name,
            'cep_telefon'        => $telGor || ! $row->cep_telefon ? $row->cep_telefon : YetkiSabitleri::telefonMaskele((string) $row->cep_telefon),
            'telefon_var'        => (bool) preg_replace('/\D/', '', (string) $row->cep_telefon),
            'email'              => $row->email,
            'profil_resim'       => $row->profil_resim,
            'kayit_tarihi'       => $row->kayit ? substr((string) $row->kayit, 0, 10) : null,
            'musteri_tipi'       => $row->musteri_tipi !== null ? (int) $row->musteri_tipi : null,
            'referans'           => MusteriSozluk::referansAdi($row->musteri_tipi),
            'ozel_notlar'        => $row->ozel_notlar,
            'kara_liste'         => (int) ($row->kara_liste ?? 0),
            'odeme_sayisi'       => $odemeSayisi,
            'toplam_odenen'      => round((float) $row->toplam_odenen, 2),
            'segment'            => Segment::ozet($odemeSayisi),
            'randevu_sayisi'     => (int) ($randevu->randevu_sayisi ?? 0),
            'son_randevu_tarihi' => $randevu->son_randevu_tarihi ?? null,
        ];
    }

    /**
     * Randevu notlari zaman cizelgesi (detay "Genel" sekmesi; Flutter "Musteri Hareketleri").
     * Eski detay: son 300 randevu, hizmet etiketleri + personel notu + randevu sonrasi not.
     */
    public function randevuNotlari(int $salonId, int $userId, ListeIstegi $l): array
    {
        $q = DB::table('randevular')->where('salon_id', $salonId)->where('user_id', $userId);
        $toplam = (clone $q)->count();

        $randevular = $q->orderByDesc('tarih')->orderByDesc('saat')->orderByDesc('id')
            ->forPage($l->sayfa, $l->sayfaBoyu)
            ->get(['id', 'tarih', 'saat', 'personel_notu', 'randevu_sonrasi_not']);

        $hizmetler = DB::table('randevu_hizmetler as rh')->join('hizmetler as h', 'h.id', '=', 'rh.hizmet_id')
            ->whereIn('rh.randevu_id', $randevular->pluck('id'))
            ->orderBy('rh.id')->get(['rh.randevu_id', 'h.hizmet_adi'])
            ->groupBy('randevu_id');

        return [
            'data' => $randevular->map(fn ($r) => [
                'id'                  => (int) $r->id,
                'tarih'               => $r->tarih,
                'saat'                => $r->saat ? substr((string) $r->saat, 0, 5) : null,
                'hizmetler'           => ($hizmetler[$r->id] ?? collect())->map(fn ($h) => ['hizmet_adi' => $h->hizmet_adi])->values()->all(),
                'personel_notu'       => self::bosIseNull($r->personel_notu),
                'randevu_sonrasi_not' => self::bosIseNull($r->randevu_sonrasi_not),
            ])->all(),
        ] + ListeIstegi::meta($l->sayfa, $l->sayfaBoyu, $toplam);
    }

    /**
     * Salon ayarlari (ekran duzeni bunlara gore — UI'da tahmin yok).
     */
    public function salonAyarlari(int $salonId): array
    {
        $kolonlar = array_values(array_filter(
            ['salon_turu_id', 'studyo_modu', 'grup_dersi_aktif', 'kara_liste_aktif', 'sadik_musteri_indirim_yuzde', 'aktif_musteri_indirim_yuzde'],
            fn ($k) => Schema::hasColumn('salonlar', $k),
        ));
        $s = DB::table('salonlar')->where('id', $salonId)->first($kolonlar);

        return [
            'danisan'          => Salonlar::danisanMi($s->salon_turu_id ?? 0),
            'studyo_modu'      => (int) ($s->studyo_modu ?? 0) === 1,
            'grup_dersi_aktif' => (int) ($s->grup_dersi_aktif ?? 0) === 1,
            // Kolon yoksa / NULL ise aktif; yalniz 0 pasif (eski KaraListeServisi::salonAktifMi).
            'kara_liste_aktif' => ! isset($s->kara_liste_aktif) || (int) $s->kara_liste_aktif === 1,
            'indirim'          => [
                'sadik_yuzde' => (float) ($s->sadik_musteri_indirim_yuzde ?? 0),
                'aktif_yuzde' => (float) ($s->aktif_musteri_indirim_yuzde ?? 0),
            ],
            'yeni_baslangic'   => substr(Segment::YENI_BASLANGIC, 0, 10),
        ];
    }

    /** Ekle/duzenle + saglik formlarinin secenekleri (UI'da sabit liste yok). */
    public function formVerisi(): array
    {
        return [
            'referanslar'     => MusteriSozluk::secenekler(MusteriSozluk::REFERANSLAR),
            'cinsiyetler'     => MusteriSozluk::secenekler(MusteriSozluk::CINSIYETLER),
            'cilt_tipleri'    => MusteriSozluk::secenekler(MusteriSozluk::CILT_TIPLERI),
            'saglik_sorulari' => array_map(fn ($k, $soru) => [
                'alan' => $k, 'soru' => $soru, 'aciklama_alani' => MusteriSozluk::SAGLIK_ACIKLAMALARI[$k] ?? null,
            ], array_keys(MusteriSozluk::SAGLIK_SORULARI), MusteriSozluk::SAGLIK_SORULARI),
        ];
    }

    private static function bosIseNull(?string $s): ?string
    {
        $s = trim((string) $s);

        return $s === '' ? null : $s;
    }
}
