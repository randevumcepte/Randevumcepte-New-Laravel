<?php

namespace App\Services\Musteri;

use App\Domain\Musteri\Segment;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Musteri LISTE servisi (salt-okuma) — eski /musterilistegetir/{durum} (web DataTables),
 * Flutter /musterilistegetir?page {arama,durum}, /musteriler?search&limit&offset
 * (secici) ve /musteri_sayilari_getir uclarinin TEK karsiligi.
 *
 * Sekme sayaclari ve liste AYNI segment kosulunu kullanir (Segment::sqlKosulu) →
 * sekmedeki sayi ile tablodaki toplam her zaman tutarli.
 */
class MusteriListeServisi
{
    public const SIRALANABILIR = ['id', 'ad', 'kayit', 'odeme', 'odenen'];

    private const SIRA_KOLONU = [
        'id'     => 'u.id',
        'ad'     => 'u.name',
        'kayit'  => 'mp.created_at',
        'odeme'  => 'odeme_sayisi',
        'odenen' => 'toplam_odenen',
    ];

    public function __construct(private MusteriOkumaServisi $okuma) {}

    /**
     * @return array{data:array, current_page:int, last_page:int, per_page:int, total:int, sayilar:array}
     */
    public function listele(int $salonId, int $durum, ?int $kisitPersonel, ListeIstegi $l, bool $telGor): array
    {
        $q = $this->filtreli($this->okuma->satirSorgusu($salonId, $kisitPersonel), $durum, $l->ara);

        // Varsayilan sira: eski liste users.id desc; "Yeni eklenen" sekmesi portfoye eklenme sirasiyla.
        $sirala = $l->sirala ?? ($durum === Segment::YENI ? 'kayit' : 'id');
        $q->orderBy(self::SIRA_KOLONU[$sirala], $l->yon)->orderBy('mp.id', $l->yon);

        $toplam = (clone $q)->reorder()->count('mp.id');
        $satirlar = $q->forPage($l->sayfa, $l->sayfaBoyu)->get();

        $randevu = $this->okuma->randevuOzetleri($salonId, $satirlar->pluck('id')->map(fn ($v) => (int) $v)->all());

        return [
            'data' => $satirlar->map(fn ($r) => $this->okuma->donustur($r, $randevu[$r->id] ?? null, $telGor))->all(),
        ] + ListeIstegi::meta($l->sayfa, $l->sayfaBoyu, $toplam)
          + ['sayilar' => $this->sayilar($salonId, $kisitPersonel)];
    }

    /**
     * Sekme sayaclari — tek sorgu. Anahtarlar Flutter MusteriSayilari ile ayni (int).
     *
     * @return array{tum_musteriler:int, sadik_musteriler:int, aktif_musteriler:int, pasif_musteriler:int, yeni_musteriler:int}
     */
    public function sayilar(int $salonId, ?int $kisitPersonel): array
    {
        $c = 'COALESCE(t.odeme_sayisi,0)';
        $r = $this->okuma->temelSorgu($salonId, $kisitPersonel)
            ->leftJoinSub($this->okuma->tahsilatOzetiSorgusu($salonId), 't', 't.user_id', '=', 'mp.user_id')
            ->selectRaw('COUNT(*) as tum, '
                .'SUM(CASE WHEN '.Segment::sqlKosulu(Segment::SADIK, $c).' THEN 1 ELSE 0 END) as sadik, '
                .'SUM(CASE WHEN '.Segment::sqlKosulu(Segment::AKTIF, $c).' THEN 1 ELSE 0 END) as aktif, '
                .'SUM(CASE WHEN '.Segment::sqlKosulu(Segment::PASIF, $c).' THEN 1 ELSE 0 END) as pasif, '
                .'SUM(CASE WHEN mp.created_at >= ? THEN 1 ELSE 0 END) as yeni', [Segment::YENI_BASLANGIC])
            ->first();

        return [
            'tum_musteriler'   => (int) ($r->tum ?? 0),
            'sadik_musteriler' => (int) ($r->sadik ?? 0),
            'aktif_musteriler' => (int) ($r->aktif ?? 0),
            'pasif_musteriler' => (int) ($r->pasif ?? 0),
            'yeni_musteriler'  => (int) ($r->yeni ?? 0),
        ];
    }

    /**
     * Hafif SECICI (randevu / satis / form ekranlarindaki musteri arama kutusu):
     * [{id, ad, telefon}] — v2-secici.js sozlesmesi. Kapsam kisiti listeyle ayni.
     */
    public function ara(int $salonId, string $q, ?int $kisitPersonel, bool $telGor, int $limit = 20): array
    {
        return $this->filtreli($this->okuma->satirSorgusu($salonId, $kisitPersonel), Segment::TUMU, $q)
            ->orderBy('u.name')->limit(max(1, min(50, $limit)))->get()
            ->map(function ($r) use ($telGor) {
                $d = $this->okuma->donustur($r, null, $telGor);

                return ['id' => $d['id'], 'ad' => $d['name'], 'telefon' => $d['cep_telefon'], 'kara_liste' => $d['kara_liste']];
            })->all();
    }

    /** Segment + arama kosullari (liste ve secici ortak). */
    private function filtreli(Builder $q, int $durum, ?string $ara): Builder
    {
        if ($durum === Segment::YENI) {
            $q->where('mp.created_at', '>=', Segment::YENI_BASLANGIC);
        } elseif ($kosul = Segment::sqlKosulu($durum, 'COALESCE(t.odeme_sayisi,0)')) {
            $q->whereRaw($kosul);
        }

        if ($like = ListeIstegi::like($ara)) {
            // Telefon parcasi: kayitlar kanonik (bastaki 0'siz) tutulur → "0532 1" de bulsun.
            $rakam = ltrim(preg_replace('/\D/', '', (string) $ara), '0');
            $q->where(function ($w) use ($like, $rakam) {
                $w->where('u.name', 'like', $like);
                if (strlen($rakam) >= 3) {
                    $w->orWhere('u.cep_telefon', 'like', '%'.$rakam.'%');
                }
            });
        }

        return $q;
    }
}
