<?php

namespace App\Services\Seans;

use App\Domain\Ders\KatilimciDurumu;
use App\Domain\Seans\SeansDurumu;
use App\Models\DersKatilimci;
use App\Services\Paket\PaketKullanimi;
use App\Services\Randevu\RandevuOrtak;
use Illuminate\Support\Facades\DB;

/**
 * Musterinin satin aldigi seans hakki havuzu — randevu ve grup dersi ORTAK kaynagi
 * (RandevuKayitServisi'nden cikarildi; eski StoreAdminController FIFO aday secimi ile ayni).
 *
 * Kapsam: tek hizmet satisi (adisyon_hizmetler) + paket (adisyon_paketler + paket_hizmetler).
 * Kullanim = adisyon_paket_seanslar satir sayisi. Paket icin hizmet bazli sayilir.
 */
class SeansHavuzu
{
    public function __construct(private PaketKullanimi $kullanim) {}

    /**
     * Bekleyen telafi (henuz makyaji yapilmamis, geldi=2) — once tek hizmet satisi, sonra
     * paket; en eski. "Bekleyen" = tarihi hedef tarihten ONCE ve bir derse BAGLI degil. Tarih tek
     * basina yetmez: D2'ye baglanan telafi D3 icin yine "once" kalir, ayni hak iki derste kullanilirdi.
     *
     * @param  int[]  $haric  ayni istekte kullanilmis telafi satirlari
     */
    public function bekleyenTelafi(int $salonId, int $userId, int $hizmetId, string $tarih, array $haric = []): ?int
    {
        foreach ([['adisyon_hizmetler', 'aps.adisyon_hizmet_id'], ['adisyon_paketler', 'aps.adisyon_paket_id']] as [$tablo, $fk]) {
            $id = DB::table('adisyon_paket_seanslar as aps')->join($tablo.' as x', $fk, '=', 'x.id')
                ->join('adisyonlar as a', 'x.adisyon_id', '=', 'a.id')
                ->where('aps.geldi', SeansDurumu::TELAFI->geldi())->where('aps.hizmet_id', $hizmetId)
                ->where('a.user_id', $userId)->where('a.salon_id', $salonId)
                ->whereDate('aps.seans_tarih', '<', $tarih)
                ->when($haric, fn ($q) => $q->whereNotIn('aps.id', $haric))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ders_katilimcilar as dk')->whereColumn('dk.aps_id', 'aps.id')
                    ->where('dk.not', DersKatilimci::NOT_TELAFI_BAGLI)->where('dk.durum', '!=', KatilimciDurumu::IPTAL->value))
                ->orderBy('aps.seans_tarih')->value('aps.id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Yeni seans adayi: kalan seansi olan tek hizmet satisi ve paket; ikisi de varsa adisyon
     * tarihi ERKEN olan (esitse paket). Bulunamazsa null.
     * Suresi bitmis / gunluk limiti dolmus paket aday olmaz. Gecerli SINIRSIZ paket varsa once o
     * kullanilir: musterinin parayla aldigi seansli paket uyelik suresinde bosa tukenmesin.
     *
     * @param  array<string,int>  $rezerve  [ 'AH:id' => n, 'AP:id:hizmet' => n ] — ayni istek icinde ayrilan
     * @return array{islem:string,seans_no:int,adisyon_hizmet_id?:int,adisyon_paket_id?:int}|null
     */
    public function yeniAday(int $salonId, int $userId, int $hizmetId, array &$rezerve = [], ?string $tarih = null): ?array
    {
        $hizmet = null;
        $q = DB::table('adisyon_hizmetler as ah')->join('adisyonlar as a', 'ah.adisyon_id', '=', 'a.id')
            ->where('ah.hizmet_id', $hizmetId)->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ah.seans_sayisi', '>', 0);
        foreach (RandevuOrtak::otomatikRandevuDegil($q, 'ah')->orderBy('a.tarih')->get(['ah.id', 'ah.seans_sayisi', 'ah.kullanilan_seans', 'ah.kullanilmayan_seans', 'a.tarih']) as $x) {
            $kul = DB::table('adisyon_paket_seanslar')->where('adisyon_hizmet_id', $x->id)->count() + ($rezerve['AH:'.$x->id] ?? 0);
            if ($kul < (int) $x->seans_sayisi) {
                $hizmet = $x;
                break;
            }
        }

        $paket = null;
        $sinirsiz = false;
        $q = DB::table('adisyon_paketler as ap')->join('adisyonlar as a', 'ap.adisyon_id', '=', 'a.id')
            ->join('paket_hizmetler as ph', 'ph.paket_id', '=', 'ap.paket_id')
            ->where('ph.hizmet_id', $hizmetId)->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ap.seans_sayisi', '>', 0);
        foreach (RandevuOrtak::otomatikRandevuDegil($q, 'ap')->orderBy('a.tarih')->distinct()->get(['ap.id', 'ap.seans_sayisi', 'ap.kullanilan_seans', 'ap.kullanilmayan_seans', 'a.tarih']) as $x) {
            $kul = DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $x->id)->where('hizmet_id', $hizmetId)->count()
                + ($rezerve['AP:'.$x->id.':'.$hizmetId] ?? 0);
            $gunAnahtar = PaketKullanimi::gunAnahtari((int) $x->id, $hizmetId, $tarih);
            if ($kul >= (int) $x->seans_sayisi || ! $this->kullanim->seansUygun((int) $x->id, $hizmetId, $tarih, $rezerve[$gunAnahtar] ?? 0)) {
                continue;
            }
            if ($this->kullanim->durum((int) $x->id)['sinirsiz'] ?? false) {
                $paket = $x;
                $sinirsiz = true;
                break;
            }
            $paket ??= $x;
        }

        $hizmetSec = ! $sinirsiz && $hizmet && (! $paket || date('Y-m-d', strtotime($hizmet->tarih)) < date('Y-m-d', strtotime($paket->tarih)));
        if ($hizmetSec) {
            $rezerve['AH:'.$hizmet->id] = ($rezerve['AH:'.$hizmet->id] ?? 0) + 1;

            return ['islem' => 'yeni_seans', 'adisyon_hizmet_id' => (int) $hizmet->id,
                'seans_no' => (int) $hizmet->kullanilan_seans + (int) $hizmet->kullanilmayan_seans + 1];
        }
        if ($paket) {
            $rezerve['AP:'.$paket->id.':'.$hizmetId] = ($rezerve['AP:'.$paket->id.':'.$hizmetId] ?? 0) + 1;
            $gunAnahtar = PaketKullanimi::gunAnahtari((int) $paket->id, $hizmetId, $tarih);
            $rezerve[$gunAnahtar] = ($rezerve[$gunAnahtar] ?? 0) + 1;

            return ['islem' => 'yeni_seans', 'adisyon_paket_id' => (int) $paket->id,
                'seans_no' => (int) $paket->kullanilan_seans + (int) $paket->kullanilmayan_seans + 1];
        }

        return null; // paket/seans hakki yok
    }

    public function hakVarMi(int $salonId, int $userId, int $hizmetId, ?string $tarih = null): bool
    {
        $rezerve = [];

        return $hizmetId > 0 && $this->yeniAday($salonId, $userId, $hizmetId, $rezerve, $tarih) !== null;
    }

    public function kalanToplam(int $salonId, int $userId, int $hizmetId): int
    {
        return (int) array_sum(array_column(
            array_filter($this->kaynaklar($salonId, $userId), fn ($k) => $k['hizmet_id'] === $hizmetId),
            'kalan_seans',
        ));
    }

    /**
     * Kalan seansi olan satislar (hizmet bazinda): otomatik ders plani kaynagi + kalan hesabi.
     * Paketteki her hizmet ayri satirdir.
     *
     * Bilincli fark: eski liste paket kalanini hizmetler arasinda ORTALAMA ile buluyordu
     * (A hizmetinden 5 kullanilinca B'nin de kalani dusuyordu); dusum hizmet bazli oldugu
     * icin kalan da hizmet bazli sayilir.
     *
     * @return array<int,array{etiket:string,hizmet_id:int,hizmet_adi:string,kalan_seans:int,adisyon_paket_id:?int,adisyon_hizmet_id:?int}>
     */
    public function kaynaklar(int $salonId, int $userId): array
    {
        $kaynaklar = [];

        $q = DB::table('adisyon_hizmetler as ah')->join('adisyonlar as a', 'ah.adisyon_id', '=', 'a.id')
            ->join('hizmetler as h', 'h.id', '=', 'ah.hizmet_id')
            ->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ah.seans_sayisi', '>', 0);
        $hizmetler = RandevuOrtak::otomatikRandevuDegil($q, 'ah')->orderBy('a.tarih')
            ->get(['ah.id', 'ah.hizmet_id', 'ah.seans_sayisi', 'h.hizmet_adi']);
        $kullanim = DB::table('adisyon_paket_seanslar')->whereIn('adisyon_hizmet_id', $hizmetler->pluck('id'))
            ->groupBy('adisyon_hizmet_id')->selectRaw('adisyon_hizmet_id as id, COUNT(*) as adet')->pluck('adet', 'id');
        foreach ($hizmetler as $h) {
            $kalan = (int) $h->seans_sayisi - (int) ($kullanim[$h->id] ?? 0);
            if ($kalan > 0) {
                $kaynaklar[] = [
                    'etiket' => $h->hizmet_adi.' (Tek Hizmet)', 'hizmet_id' => (int) $h->hizmet_id, 'hizmet_adi' => $h->hizmet_adi,
                    'kalan_seans' => $kalan, 'sinirsiz' => false, 'adisyon_paket_id' => null, 'adisyon_hizmet_id' => (int) $h->id,
                ];
            }
        }

        $q = DB::table('adisyon_paketler as ap')->join('adisyonlar as a', 'ap.adisyon_id', '=', 'a.id')
            ->join('paketler as p', 'p.id', '=', 'ap.paket_id')
            ->join('paket_hizmetler as ph', 'ph.paket_id', '=', 'ap.paket_id')
            ->join('hizmetler as h', 'h.id', '=', 'ph.hizmet_id')
            ->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ap.seans_sayisi', '>', 0);
        $paketler = RandevuOrtak::otomatikRandevuDegil($q, 'ap')->orderBy('a.tarih')->distinct()
            ->get(['ap.id', 'ap.seans_sayisi', 'ph.hizmet_id', 'h.hizmet_adi', 'p.paket_adi', 'a.tarih']);
        $kullanim = DB::table('adisyon_paket_seanslar')->whereIn('adisyon_paket_id', $paketler->pluck('id')->unique())
            ->groupBy('adisyon_paket_id', 'hizmet_id')->selectRaw('adisyon_paket_id as paket, hizmet_id as hizmet, COUNT(*) as adet')->get()
            ->mapWithKeys(fn ($s) => [$s->paket.':'.$s->hizmet => (int) $s->adet]);
        $durumlar = $this->kullanim->durumlar($paketler->pluck('id')->unique()->map(fn ($i) => (int) $i)->all());
        foreach ($paketler as $p) {
            $kalan = (int) $p->seans_sayisi - (int) ($kullanim[$p->id.':'.$p->hizmet_id] ?? 0);
            if ($kalan > 0 && ! ($durumlar[$p->id]['suresi_doldu'] ?? false)) {
                $kaynaklar[] = [
                    'etiket' => $p->hizmet_adi.' — '.$p->paket_adi.' (Paket)', 'hizmet_id' => (int) $p->hizmet_id, 'hizmet_adi' => $p->hizmet_adi,
                    'kalan_seans' => $kalan, 'sinirsiz' => $durumlar[$p->id]['sinirsiz'] ?? false, 'adisyon_paket_id' => (int) $p->id, 'adisyon_hizmet_id' => null,
                ];
            }
        }

        return $kaynaklar;
    }
}
