<?php

namespace App\Services\Stok;

use App\Domain\Stok\HareketTipi;
use App\Domain\Stok\Miktar;
use App\Domain\Stok\StokSeviyesi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Urunler;
use App\Support\AktiviteLog;
use App\Support\ListeIstegi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Urun tanimi + listesi (Stok Yonetimi ana tablosu, hizli satis / sayim / alis secicileri).
 *
 * Liste: arama + kategori/tip filtresi + siralama SQL'de; stok SEVIYESI filtresi
 * (tukendi/kritik/dusuk/uyari) Domain kuraliyla PHP'de — kural tek yerde kalsin
 * diye SQL'e kopyalanmaz (salon urun sayisi yuzlerle sinirli).
 */
class UrunServisi
{
    /** Siralama beyaz listesi (ListeIstegi sirala). */
    public const SIRALANABILIR = ['urun_adi', 'stok_adedi', 'fiyat', 'alis_fiyati', 'tip', 'kategori'];

    public function __construct(
        private StokKapsam $kapsam,
        private StokHareketServisi $hareket,
    ) {}

    /**
     * @param  array{kategori_id?:mixed, tip?:?string, seviye?:?string, depo_id?:mixed, tumu?:bool}  $filtre
     *         depo_id verilirse her satirda 'depo_stok' (o depodaki stok — sayim/transfer icin)
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste  ListeIstegi::dizi()
     */
    public function listele(int $salonId, array $filtre, array $liste): array
    {
        $q = Urunler::query()->with(['kategori:id,ad,renk,ikon', 'tedarikci:id,ad'])
            ->where('salon_id', $salonId)->where('aktif', 1);

        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('urun_adi', 'like', $like)->orWhere('barkod', 'like', $like)->orWhere('sku', 'like', $like));
        }
        if (! empty($filtre['kategori_id'])) {
            $q->where('kategori_id', (int) $filtre['kategori_id']);
        }
        if (! empty($filtre['tip'])) {
            $q->where('tip', $filtre['tip']);
        }
        $depoId = (int) ($filtre['depo_id'] ?? 0);
        if ($depoId) {
            $q->select('urunler.*')->selectSub(fn ($s) => $s->from('urun_depo_stoklari')->select('stok')
                ->whereColumn('urun_id', 'urunler.id')->where('depo_id', $depoId)->limit(1), 'depo_stok');
        }
        $this->sirala($q, $liste['sirala'] ?? null, $liste['yon'] ?? 'asc');

        $satirlar = $q->get()->map(fn (Urunler $u) => StokSunumu::urun($u) + ($depoId ? ['depo_stok' => (float) $u->depo_stok] : []));

        if (! empty($filtre['seviye'])) {
            $s = $filtre['seviye'];
            $satirlar = $satirlar->filter(fn ($u) => $s === 'uyari' ? StokSeviyesi::uyariMi($u['stok_seviyesi']) : $u['stok_seviyesi'] === $s);
        }
        $satirlar = $satirlar->values();

        if (! empty($filtre['tumu'])) {
            return ['data' => $satirlar->all()] + ListeIstegi::meta(1, max(1, $satirlar->count()), $satirlar->count());
        }

        $sayfa = $liste['page'];
        $boy = $liste['per_page'];

        return ['data' => $satirlar->forPage($sayfa, $boy)->values()->all()] + ListeIstegi::meta($sayfa, $boy, $satirlar->count());
    }

    /** Urun + depo bazinda stok dagilimi. */
    public function getir(int $id): array
    {
        $u = Urunler::with(['kategori', 'tedarikci'])->find($id) ?? throw new BulunamadiException('Ürün bulunamadı');

        $depolar = DB::table('urun_depo_stoklari as s')->join('depolar as d', 'd.id', '=', 's.depo_id')
            ->where('s.urun_id', $id)->orderByDesc('d.varsayilan')->orderBy('d.depo_adi')
            ->get(['s.depo_id', 'd.depo_adi', 'd.aktif', 's.stok'])
            ->map(fn ($d) => ['depo_id' => (int) $d->depo_id, 'depo_adi' => $d->depo_adi, 'depo_aktif' => (bool) $d->aktif, 'stok' => (float) $d->stok])
            ->all();

        return StokSunumu::urun($u) + ['depo_stoklari' => $depolar];
    }

    /** Barkod okutma (hizli satis / mobil tarayici) — salonun AKTIF urunu, tam eslesme. */
    public function barkodla(int $salonId, string $barkod): array
    {
        $u = Urunler::with(['kategori', 'tedarikci'])->where('salon_id', $salonId)->where('aktif', 1)
            ->where('barkod', trim($barkod))->first();

        return $u ? StokSunumu::urun($u) : throw new BulunamadiException('Bu barkodla ürün bulunamadı');
    }

    /**
     * Ekle / guncelle. stok_adedi GONDERILIRSE varsayilan depoda fark kadar hareket
     * yazilir (yeni urun: 'acilis', mevcut: 'manuel' duzeltme) — eskiyle ayni anlam.
     *
     * @param  array  $v  UrunKaydetIstegi::validated()
     */
    public function kaydet(int $salonId, ?int $id, array $v, int $yetkiliId): array
    {
        $yeni = $id === null;
        $this->kapsam->aitOlmali('kategori', $v['kategori_id'] ?? null, $salonId);
        $this->kapsam->aitOlmali('tedarikci', $v['tedarikci_id'] ?? null, $salonId);
        $this->barkodBenzersiz($salonId, $v['barkod'] ?? null, $id);

        $stokVar = array_key_exists('stok_adedi', $v) && $v['stok_adedi'] !== null;
        $istenen = $stokVar ? Miktar::yuvarla((float) $v['stok_adedi']) : null;
        unset($v['stok_adedi']);

        $urun = DB::transaction(function () use ($salonId, $id, $yeni, $v, $istenen, $yetkiliId) {
            $u = $yeni ? new Urunler(['salon_id' => $salonId, 'aktif' => 1]) : Urunler::lockForUpdate()->findOrFail($id);
            $onceki = (float) ($u->stok_adedi ?? 0);
            $u->fill($v);
            if ($yeni) {
                $u->tip = $u->tip ?: 'satis';
                $u->birim = $u->birim ?: 'adet';
                $u->stok_adedi = 0;
            }
            if (! $u->varsayilan_depo_id) {
                $u->varsayilan_depo_id = $this->hareket->varsayilanDepo($salonId)->id;
            }
            $u->save();

            $fark = $istenen === null ? 0.0 : Miktar::fark($istenen, $yeni ? 0.0 : $onceki);
            if (! Miktar::sifirMi($fark)) {
                $this->hareket->kaydet([
                    'salon_id'          => $salonId,
                    'urun_id'           => (int) $u->id,
                    'depo_id'           => (int) $u->varsayilan_depo_id,
                    'miktar'            => $fark,
                    'tip'               => $yeni ? HareketTipi::Acilis : HareketTipi::Manuel,
                    'birim_alis_fiyati' => $u->alis_fiyati,
                    'aciklama'          => $yeni ? 'Ürün oluşturulurken başlangıç stoğu' : 'Ürün düzenleme - stok düzeltme',
                    'kullanici_id'      => $yetkiliId,
                ]);
            }

            return $u;
        });

        AktiviteLog::yaz($salonId, $yetkiliId, $yeni ? 'urun_ekle' : 'urun_guncelle', 'urun', (int) $urun->id,
            (string) $urun->urun_adi, $yeni ? 'Yeni ürün eklendi' : 'Ürün güncellendi');

        return $this->getir((int) $urun->id);
    }

    /** Pasife al (fiziksel silme yok — satis/hareket gecmisi bozulmasin). */
    public function sil(int $id, int $yetkiliId): array
    {
        $u = Urunler::findOrFail($id);
        $u->aktif = 0;
        $u->save();
        AktiviteLog::yaz((int) $u->salon_id, $yetkiliId, 'urun_sil', 'urun', (int) $u->id, (string) $u->urun_adi, 'Ürün pasif yapıldı');

        return ['id' => (int) $u->id, 'aktif' => false];
    }

    /** Ayni salonda iki aktif urun ayni barkodu tasiyamaz (okutma tek urune gitmeli). */
    private function barkodBenzersiz(int $salonId, ?string $barkod, ?int $haricId): void
    {
        $barkod = trim((string) $barkod);
        if ($barkod === '') {
            return;
        }
        $cakisan = Urunler::where('salon_id', $salonId)->where('aktif', 1)->where('barkod', $barkod)
            ->when($haricId, fn ($q) => $q->whereKeyNot($haricId))->value('urun_adi');
        if ($cakisan !== null) {
            throw new GecersizIslemException("Bu barkod \"{$cakisan}\" ürününde kayıtlı.");
        }
    }

    private function sirala(Builder $q, ?string $alan, string $yon): void
    {
        match ($alan) {
            'kategori' => $q->orderBy(DB::table('urun_kategoriler')->select('ad')->whereColumn('id', 'urunler.kategori_id'), $yon),
            'stok_adedi', 'fiyat', 'alis_fiyati', 'tip' => $q->orderBy($alan, $yon),
            default => null,
        };
        $q->orderBy('urun_adi', $alan === 'urun_adi' ? $yon : 'asc');
    }
}
