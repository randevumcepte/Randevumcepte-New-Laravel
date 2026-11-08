<?php

namespace App\Services\Paket;

use App\Exceptions\BulunamadiException;
use App\Models\Paketler;
use App\Support\ListeIstegi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Paket katalogu OKUMA — Paket Yonetimi tablosu, satis secicileri (katalog), adisyona
 * paket ekleme fiyati. Etkin fiyat/seans PaketTutari'ndan (PaketSunumu) gelir; kural
 * SQL'e kopyalanmaz. Salon basina paket sayisi kucuk (onlar-yuzler) oldugundan
 * siralama / sayfalama sunum sonrasi PHP'de yapilir (UrunServisi ile ayni yaklasim).
 */
class PaketSorgusu
{
    /** Siralama beyaz listesi (ListeIstegi sirala). */
    public const SIRALANABILIR = ['paket_adi', 'fiyat', 'miktar', 'id'];

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste  ListeIstegi::dizi()
     * @return array{data: array<int,array>, current_page:int, last_page:int, per_page:int, total:int}
     */
    public function listele(int $salonId, array $liste, bool $tumu = false): array
    {
        $q = $this->aktifler($salonId);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('paket_adi', 'like', $like)
                ->orWhereHas('hizmetler.hizmet', fn ($h) => $h->where('hizmet_adi', 'like', $like)));
        }
        $satirlar = $this->sirala($q->get()->map(fn (Paketler $p) => PaketSunumu::paket($p)), $liste['sirala'] ?? 'id', $liste['yon'] ?? 'desc');

        if ($tumu) {
            return ['data' => $satirlar->all()] + ListeIstegi::meta(1, max(1, $satirlar->count()), $satirlar->count());
        }

        return ['data' => $satirlar->forPage($liste['page'], $liste['per_page'])->values()->all()]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], $satirlar->count());
    }

    /** Tek paket (pasif dahil — gecmis satislarin paketi de gorulebilsin). */
    public function getir(int $id): array
    {
        return PaketSunumu::paket(Paketler::with('hizmetler.hizmet')->find($id) ?? throw new BulunamadiException('Paket bulunamadı'));
    }

    /** Paketin salonu (kapsam denetimi); yoksa null. */
    public function salonId(int $id): ?int
    {
        $s = Paketler::whereKey($id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    /**
     * Satista kullanilacak etkin fiyat/seans — paket bu salonun AKTIF paketi olmali
     * (baska isletmenin / silinmis paketin satilmasi engellenir).
     *
     * seans: satista varsayilan hak, BOLGE BASINA (PaketTutari::satisSeansi) — toplam degil.
     *
     * @return array{fiyat: float, seans: int, paket_adi: string, sinirsiz: bool}
     */
    public function satisTutari(int $paketId, int $salonId): array
    {
        $p = $this->aktifler($salonId)->whereKey($paketId)->first() ?? throw new BulunamadiException('Paket bulunamadı veya bu işletmeye ait değil');
        $s = PaketSunumu::paket($p);

        return ['fiyat' => $s['fiyat'], 'seans' => $s['satis_seans'], 'paket_adi' => $s['paket_adi'], 'sinirsiz' => $s['sinirsiz'] === 1];
    }

    /**
     * Mobil paket tablosu — eski ApiController::paketler. Ham fiyat ve miktar doner (etkin deger
     * degil), hizmeti olmayan paket listelenmez, sira id, sayfa 10.
     */
    public function mobilSayfa(int $salonId, string $arama, int $sayfa): array
    {
        $like = '%'.$arama.'%';

        return Paketler::with('hizmetler.hizmet')
            ->whereHas('hizmetler.hizmet', function ($q) use ($like) {
                $q->where('hizmet_adi', 'like', $like);
                $q->orWhere('paket_adi', 'like', $like); // dis sorgudaki paketler kolonu
            })
            ->where('salon_id', $salonId)
            ->where('aktif', 1)
            ->paginate(10, ['*'], 'page', $sayfa)
            ->toArray();
    }

    /** Mobil paket secicileri — eski paketget (id artan) ve paketler_liste (id azalan); ham deger. */
    public function mobilKatalog(int $salonId, string $yon): array
    {
        return Paketler::with('hizmetler.hizmet')->where('salon_id', $salonId)->where('aktif', 1)
            ->orderBy('id', $yon === 'desc' ? 'desc' : 'asc')->get()->toArray();
    }

    private function aktifler(int $salonId): Builder
    {
        return Paketler::query()->with('hizmetler.hizmet')->where('salon_id', $salonId)->where('aktif', 1);
    }

    private function sirala(Collection $satirlar, ?string $alan, string $yon): Collection
    {
        $alan = in_array($alan, self::SIRALANABILIR, true) ? $alan : 'id';
        // Ad: Turkce alfabe sirasi (intl varsa Collator, yoksa kucuk harf karsilastirma).
        $tr = class_exists(\Collator::class) ? new \Collator('tr_TR') : null;
        $adKarsilastir = fn (string $a, string $b) => $tr ? $tr->compare($a, $b) : strcmp(mb_strtolower($a), mb_strtolower($b));
        $sirali = $alan === 'paket_adi'
            ? $satirlar->sort(fn ($a, $b) => $adKarsilastir($a['paket_adi'], $b['paket_adi']) ?: $a['id'] <=> $b['id'])
            : $satirlar->sortBy([[$alan, 'asc'], ['id', 'asc']]);

        return ($yon === 'desc' ? $sirali->reverse() : $sirali)->values();
    }
}
