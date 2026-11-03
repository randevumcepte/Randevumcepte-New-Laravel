<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaFiltresi;
use App\Domain\Musteri\Segment;
use App\Services\Musteri\MusteriOkumaServisi;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Arama listesi FİLTRE MOTORU — eski App\Services\AramaFiltreService::build karşılığı.
 * Taban v2 müşteri portföyü sorgusu (MusteriOkumaServisi::temelSorgu: aktif portföy ⋈ users);
 * müşteri durumu v2 TEK segment kuralı (Domain\Musteri\Segment — müşteri listesi sekmeleriyle aynı),
 * aranabilmek için telefon zorunlu. Sıra isme göre (toplu "ilk 100 / 101–200" seçimi ekrandakiyle aynı).
 */
class AramaFiltreSorgusu
{
    public function __construct(private MusteriOkumaServisi $musteri) {}

    public function sorgu(int $salonId, AramaFiltresi $f): Builder
    {
        $q = $this->musteri->temelSorgu($salonId)
            ->whereNotNull('u.cep_telefon')->where('u.cep_telefon', '!=', '');

        if ($f->kayit === 'son1yil') {
            $q->where('mp.created_at', '>=', now()->subYear());
        } elseif ($f->kayit === 'ozel') {
            $f->kayitT1 && $q->where('mp.created_at', '>=', $f->kayitT1.' 00:00:00');
            $f->kayitT2 && $q->where('mp.created_at', '<=', $f->kayitT2.' 23:59:59');
        }

        if ($f->durum !== null) {
            $kod = ['pasif' => Segment::PASIF, 'aktif' => Segment::AKTIF, 'sadik' => Segment::SADIK][$f->durum];
            $q->leftJoinSub($this->musteri->tahsilatOzetiSorgusu($salonId), 't', 't.user_id', '=', 'mp.user_id')
                ->whereRaw(Segment::sqlKosulu($kod, 'COALESCE(t.odeme_sayisi,0)'));
        }

        if ($f->gelmeyen !== null) {
            // Son GELDİĞİ (onaylı + geldi) randevu N günden eski; hiç gelmemiş olan bu filtreye girmez.
            $q->whereRaw('(SELECT MAX(r.tarih) FROM randevular r WHERE r.user_id = mp.user_id AND r.salon_id = ? AND r.durum = 1 AND r.randevuya_geldi = 1) < ?',
                [$salonId, now()->subDays($f->gelmeyen)->toDateString()]);
        }

        if ($f->satis !== null) {
            $adisyon = fn ($s) => $s->select(DB::raw(1))->from('adisyonlar as a')->whereColumn('a.user_id', 'mp.user_id')->where('a.salon_id', $salonId);
            $f->satis === 'var' ? $q->whereExists($adisyon) : $q->whereNotExists($adisyon);
        }

        if ($f->cinsiyet !== null) {
            $q->where('u.cinsiyet', $f->cinsiyet);
        }

        if ($f->dogumgunuYaklasan !== null) {
            $bas = now()->format('m-d');
            $son = now()->addDays($f->dogumgunuYaklasan)->format('m-d');
            $bas <= $son
                ? $q->whereRaw("DATE_FORMAT(u.dogum_tarihi,'%m-%d') BETWEEN ? AND ?", [$bas, $son])
                : $q->whereRaw("(DATE_FORMAT(u.dogum_tarihi,'%m-%d') >= ? OR DATE_FORMAT(u.dogum_tarihi,'%m-%d') <= ?)", [$bas, $son]);   // yıl sonu sarması
        }

        if ($f->bayrak('kara_liste_haric')) {
            $q->whereRaw('COALESCE(mp.kara_liste,0) = 0');   // NULL da "kara listede değil"
        }
        if ($f->bayrak('whatsapp_onay')) {
            $q->where('u.whatsapp_onay', 1);
        }
        $randevu = fn ($s) => $s->select(DB::raw(1))->from('randevular as r')->whereColumn('r.user_id', 'mp.user_id')->where('r.salon_id', $salonId);
        if ($f->bayrak('hic_randevu_yok')) {
            $q->whereNotExists($randevu);
        }
        if ($f->bayrak('iptal_eden')) {
            $q->whereExists(fn ($s) => $randevu($s)->whereIn('r.durum', [2, 3]));
        }

        $f->ilId && $q->where('u.il_id', $f->ilId);
        $f->ilceId && $q->where('u.ilce_id', $f->ilceId);

        if ($desen = ListeIstegi::like($f->ara)) {
            $rakam = preg_replace('/\D/', '', (string) $f->ara);
            $q->where(fn ($w) => $w->where('u.name', 'like', $desen)->when(strlen($rakam) >= 3, fn ($x) => $x->orWhere('u.cep_telefon', 'like', '%'.$rakam.'%')));
        }

        return $q->orderBy('u.name')->orderBy('mp.user_id');
    }

    /** Filtreye uyan benzersiz müşteri sayısı (portföy (user, salon) başına tekil). */
    public function say(int $salonId, AramaFiltresi $f): int
    {
        return (clone $this->sorgu($salonId, $f))->reorder()->distinct()->count('mp.user_id');
    }

    /**
     * user_id'ler isim sırasıyla; $bas/$adet verilirse o aralık (1 tabanlı sıra).
     *
     * @return int[]
     */
    public function idler(int $salonId, AramaFiltresi $f, ?int $bas = null, ?int $adet = null): array
    {
        $q = $this->sorgu($salonId, $f)->distinct()->select('mp.user_id', 'u.name');
        if ($bas !== null) {
            $q->skip(max(0, $bas - 1))->take(max(1, (int) $adet));
        }

        return array_values(array_unique(array_map('intval', $q->pluck('mp.user_id')->all())));
    }
}
