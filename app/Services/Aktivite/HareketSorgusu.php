<?php

namespace App\Services\Aktivite;

use App\Domain\Aktivite\HareketKategorisi;
use App\Domain\Aktivite\HareketKaynagi;
use App\Domain\Aktivite\HareketKatalogu;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Log hareketleri OKUMA (salon_aktivite_log) — eski StoreAdminController@logHareketleri'nin
 * v2 karşılığı. Tablo eski Audit + PanelAktiviteLog ve yeni AktiviteLog ile ORTAK yazılır;
 * burada yalnız okunur. Çıktı biçimi HareketSunumu'nda.
 *
 * Filtreler hepsi isteğe bağlı ve birleştirilebilir; böylece aynı uç hem "Log Hareketleri"
 * sayfasını hem de başka ekranlara gömülen hareket tablolarını (ör. müşteri kartı:
 * hedef_tipi=musteri&hedef_id=…, personel: kullanici_id=…) besler.
 */
class HareketSorgusu
{
    /** Sıralama beyaz listesi (ListeIstegi sirala) → SQL kolonu. 'zaman' = PK (created_at ile aynı sıra, indeksli). */
    public const SIRALAMA = ['zaman' => 'id', 'kullanici' => 'user_name', 'islem' => 'action', 'hedef' => 'target_label'];

    /** Seçenek listeleri (DISTINCT taramaları) önbellek süresi — saniye. */
    private const SECENEK_TTL = 600;

    private const SECIM = ['id', 'user_id', 'user_type', 'user_name', 'user_rol', 'action', 'target_type', 'target_id',
        'target_label', 'aciklama', 'meta', 'ip', 'created_at'];

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste  ListeIstegi::dizi()
     * @param  array{islem?:?string, kategori?:?string, kaynak?:?string, kullanici_id?:?int, hedef_tipi?:?string,
     *               hedef_id?:?int, tarih_bas?:?string, tarih_bit?:?string}  $filtre
     * @return array{data: array, current_page:int, last_page:int, per_page:int, total:int}
     */
    public function listele(int $salonId, array $liste, array $filtre): array
    {
        $q = $this->filtreli($salonId, $filtre);
        $this->ara($q, $liste['ara'] ?? null);

        $toplam = (clone $q)->count();
        $kolon = self::SIRALAMA[$liste['sirala'] ?? ''] ?? 'id';
        $satirlar = $q->select(self::SECIM)->orderBy($kolon, $liste['yon'] ?? 'desc')->orderByDesc('id')
            ->forPage($liste['page'], $liste['per_page'])->get();

        return ['data' => $satirlar->map(fn ($r) => HareketSunumu::satir($r))->values()->all()]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    /**
     * Filtre seçenekleri — salonun GERÇEK verisinden (yalnız kayıtta geçen işlem/kullanıcı/hedef);
     * kategoriler + kaynaklar sözlükten (renk açıklaması da buradan çizilir).
     */
    public function secenekler(int $salonId): array
    {
        $islemler = array_map(function (string $kod) {
            $i = HareketKatalogu::islem($kod);

            return ['kod' => $kod, 'etiket' => $i['etiket'], 'kategori' => $i['kategori']->value];
        }, $this->islemKodlari($salonId));
        usort($islemler, fn ($a, $b) => strnatcasecmp($a['etiket'], $b['etiket']));

        $hedefler = $this->onbellek($salonId, 'hedefler', fn () => $this->tablo($salonId)
            ->whereNotNull('target_type')->where('target_type', '<>', '')->distinct()->orderBy('target_type')->pluck('target_type')->all());

        return [
            'kategoriler'   => array_map(fn (HareketKategorisi $k) => $k->dizi(), HareketKategorisi::cases()),
            'kaynaklar'     => array_map(fn (HareketKaynagi $k) => $k->dizi(), HareketKaynagi::cases()),
            'islemler'      => $islemler,
            'kullanicilar'  => $this->onbellek($salonId, 'kullanicilar', fn () => $this->tablo($salonId)
                ->whereNotNull('user_id')->select('user_id', DB::raw('MAX(user_name) as ad'))->groupBy('user_id')->orderBy('ad')->get()
                ->map(fn ($k) => ['id' => (int) $k->user_id, 'ad' => (string) ($k->ad ?: '#'.$k->user_id)])->all()),
            'hedef_tipleri' => array_map(fn (string $t) => ['kod' => $t, 'etiket' => HareketKatalogu::hedefEtiketi($t)], $hedefler),
        ];
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    private function tablo(int $salonId): Builder
    {
        return DB::table('salon_aktivite_log')->where('salon_id', $salonId);
    }

    private function filtreli(int $salonId, array $f): Builder
    {
        $q = $this->tablo($salonId)
            ->when($f['islem'] ?? null, fn ($w, $v) => $w->where('action', $v))
            ->when($f['kullanici_id'] ?? null, fn ($w, $v) => $w->where('user_id', $v))
            ->when($f['hedef_tipi'] ?? null, fn ($w, $v) => $w->where('target_type', $v))
            ->when($f['hedef_id'] ?? null, fn ($w, $v) => $w->where('target_id', $v))
            // Gün sınırları aralık olarak (whereDate indeksi kullanamaz).
            ->when($f['tarih_bas'] ?? null, fn ($w, $v) => $w->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['tarih_bit'] ?? null, fn ($w, $v) => $w->where('created_at', '<', Carbon::parse($v)->addDay()->toDateString().' 00:00:00'));

        if ($kategori = HareketKategorisi::koddan($f['kategori'] ?? null)) {
            // Kategori DB'de yok: salonda geçen kodlar arasından bu kategoriye düşenler.
            $q->whereIn('action', array_values(array_filter($this->islemKodlari($salonId), fn ($k) => HareketKatalogu::kategori($k) === $kategori)) ?: ['']);
        }
        if ($kaynak = HareketKaynagi::koddan($f['kaynak'] ?? null)) {
            $this->kaynakFiltresi($q, $kaynak);
        }

        return $q;
    }

    /** meta.kaynak TEXT içinde (json_encode bitişik yazar: "kaynak":"mobil"); kaynaksız kayıtlar kullanıcı tipine göre. */
    private function kaynakFiltresi(Builder $q, HareketKaynagi $kaynak): void
    {
        $q->where(function ($w) use ($kaynak) {
            foreach ($kaynak->metaDegerleri() as $deger) {
                $w->orWhere('meta', 'like', '%"kaynak":"'.$deger.'"%');
            }
            $sistem = $kaynak->kaynaksizSistemMi();
            if ($sistem !== null) {
                $w->orWhere(function ($k) use ($sistem) {
                    $k->where(fn ($m) => $m->whereNull('meta')->orWhere('meta', 'not like', '%"kaynak":%'));
                    $sistem ? $k->where('user_type', 'sistem') : $k->where(fn ($t) => $t->whereNull('user_type')->orWhere('user_type', '<>', 'sistem'));
                });
            }
        });
    }

    /** Arama: satırda GÖRÜNEN içerik (kullanıcı, hedef, açıklama, işlem kodu). */
    private function ara(Builder $q, ?string $ara): void
    {
        $like = ListeIstegi::like($ara);
        if (! $like) {
            return;
        }
        $q->where(fn ($w) => $w->where('target_label', 'like', $like)->orWhere('aciklama', 'like', $like)
            ->orWhere('user_name', 'like', $like)->orWhere('action', 'like', $like));
    }

    /** @return string[] Salonda geçen işlem kodları (önbellekli). */
    private function islemKodlari(int $salonId): array
    {
        return $this->onbellek($salonId, 'islemler', fn () => $this->tablo($salonId)
            ->whereNotNull('action')->where('action', '<>', '')->distinct()->pluck('action')->all());
    }

    private function onbellek(int $salonId, string $ad, \Closure $uret): array
    {
        return Cache::remember("v2_hareket:{$ad}:{$salonId}", self::SECENEK_TTL, $uret);
    }
}
