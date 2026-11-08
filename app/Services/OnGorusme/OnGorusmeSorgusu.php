<?php

namespace App\Services\OnGorusme;

use App\Domain\OnGorusme\GorusmeDurumu;
use App\Exceptions\BulunamadiException;
use App\Models\Paketler;
use App\Services\Paket\PaketSunumu;
use App\Services\Musteri\PortfoyKapsami;
use App\Services\Yetki\YetkiServisi;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ön görüşme OKUMA — liste (sekme sayaçlarıyla), tek kayıt, kapsam yardımcıları.
 * Eski web ongorusmegetir (HTML üreten SQL) + mobil v1 ongorusmeget / ongorusmegetgunluk
 * uçlarının TEK karşılığı; çıktı biçimi OnGorusmeSunumu'nda.
 *
 * KAPSAM (eski iki kanal farklıydı; v2'de tek kural): personel ROLÜNDEKİ (role 5) kullanıcı
 * yalnızca KENDİ yaptığı görüşmeleri görür (mobil v1 davranışı; eski web personeli de
 * tüm salonu gösteriyordu). İstekteki personel_id bu kısıtı aşamaz.
 */
class OnGorusmeSorgusu
{
    /** Sıralama beyaz listesi (ListeIstegi sirala) → SQL kolonu. */
    public const SIRALAMA = [
        'created_at' => 'og.created_at', 'ad_soyad' => 'og.ad_soyad', 'hatirlatma_tarihi' => 'og.hatirlatma_tarihi',
        'durum' => 'og.durum', 'personel_adi' => 'sp.personel_adi', 'id' => 'og.id',
    ];

    private const SECIM = [
        'og.id', 'og.user_id', 'og.ad_soyad', 'og.cep_telefon', 'og.email', 'og.cinsiyet', 'og.adres', 'og.meslek',
        'og.il_id', 'og.aciklama', 'og.musteri_tipi', 'og.hatirlatma_tarihi', 'og.on_gorusme_saati', 'og.durum',
        'og.satisyapilmadi_not', 'og.paket_id', 'og.urun_id', 'og.hizmet_id', 'og.personel_id', 'og.created_at',
        'sp.personel_adi', 'p.paket_adi', 'ur.urun_adi', 'ur.fiyat as urun_fiyat', 'h.hizmet_adi', 'mu.name as musteri_adi',
    ];

    public function __construct(
        private YetkiServisi $yetki,
        private PortfoyKapsami $portfoy,
    ) {}

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste  ListeIstegi::dizi()
     * @param  array{durum?:?string, tarih_bas?:?string, tarih_bit?:?string, personel_id?:?int}  $filtre
     * @param  array{kisitli_personel:?int, telefon_gor:bool, yazabilir:bool}  $ctx
     * @return array{data: array, current_page:int, last_page:int, per_page:int, total:int, sayaclar: array<string,int>}
     */
    public function listele(int $salonId, array $liste, array $filtre, array $ctx): array
    {
        $q = $this->kapsamli($salonId, $ctx['kisitli_personel'])
            ->when($filtre['tarih_bas'] ?? null, fn ($w, $t) => $w->where('og.hatirlatma_tarihi', '>=', $t))
            ->when($filtre['tarih_bit'] ?? null, fn ($w, $t) => $w->where('og.hatirlatma_tarihi', '<=', $t.' 23:59:59'))
            ->when($filtre['personel_id'] ?? null, fn ($w, $p) => $w->where('og.personel_id', $p));
        $this->ara($q, $liste['ara'] ?? null);

        // Sekme sayaçları: durum filtresi HARİÇ aynı kapsam / arama.
        $s = (clone $q)->selectRaw('COUNT(*) as tumu, SUM(og.durum IS NULL) as beklemede, SUM(og.durum = 1) as satis_yapildi, SUM(og.durum = 0) as satis_yapilmadi')->first();
        $sayaclar = array_map('intval', (array) $s);

        if ($durum = GorusmeDurumu::koddan($filtre['durum'] ?? null)) {
            $durum->db() === null ? $q->whereNull('og.durum') : $q->where('og.durum', $durum->db());
        }
        $toplam = (clone $q)->count();
        $kolon = self::SIRALAMA[$liste['sirala'] ?? ''] ?? 'og.created_at';
        $satirlar = $q->select(self::SECIM)->orderBy($kolon, $liste['yon'] ?? 'desc')->orderByDesc('og.id')
            ->forPage($liste['page'], $liste['per_page'])->get();

        return ['data' => $this->sun($satirlar, $ctx)]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam)
            + ['sayaclar' => $sayaclar];
    }

    /** Tek görüşme (kapsam dışıysa 404 — varlık sızdırılmaz). */
    public function getir(int $id, array $ctx): array
    {
        $r = $this->kapsamli($this->salonId($id) ?? 0, $ctx['kisitli_personel'])->where('og.id', $id)->select(self::SECIM)->get();

        return $this->sun($r, $ctx)[0] ?? throw new BulunamadiException('Ön görüşme bulunamadı');
    }

    /** Görüşmenin salonu (kapsam denetimi); yoksa null. */
    public function salonId(int $id): ?int
    {
        $s = DB::table('on_gorusmeler')->where('id', $id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    /** Kısıtlı personel yalnız kendi görüşmesine erişir; kısıt yoksa her zaman true. */
    public function erisilebilirMi(int $id, ?int $kisitliPersonel): bool
    {
        return ! $kisitliPersonel
            || (int) DB::table('on_gorusmeler')->where('id', $id)->value('personel_id') === $kisitliPersonel;
    }

    /**
     * Yalnız kendi görüşmelerini görecek personel id'si; kısıt yoksa null.
     * (Personel rolündeki yetkili → kendi salon_personelleri kaydı.)
     */
    public function kisitliPersonel(int $yetkiliId, int $salonId): ?int
    {
        $pid = $this->portfoy->personelId($yetkiliId, $salonId);

        return $pid && $this->yetki->personelRolundeMi($pid, $salonId) ? $pid : null;
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    private function kapsamli(int $salonId, ?int $kisitliPersonel): Builder
    {
        return DB::table('on_gorusmeler as og')
            ->leftJoin('salon_personelleri as sp', 'sp.id', '=', 'og.personel_id')
            ->leftJoin('paketler as p', 'p.id', '=', 'og.paket_id')
            ->leftJoin('urunler as ur', 'ur.id', '=', 'og.urun_id')
            ->leftJoin('hizmetler as h', 'h.id', '=', 'og.hizmet_id')
            ->leftJoin('users as mu', 'mu.id', '=', 'og.user_id')
            ->where('og.salon_id', $salonId)
            ->when($kisitliPersonel, fn ($w, $p) => $w->where('og.personel_id', $p));
    }

    private function ara(Builder $q, ?string $ara): void
    {
        $like = ListeIstegi::like($ara);
        if (! $like) {
            return;
        }
        $rakam = preg_replace('/\D/', '', (string) $ara);
        $q->where(function ($w) use ($like, $rakam) {
            $w->where('og.ad_soyad', 'like', $like)->orWhere('sp.personel_adi', 'like', $like)
                ->orWhere('p.paket_adi', 'like', $like)->orWhere('ur.urun_adi', 'like', $like)->orWhere('h.hizmet_adi', 'like', $like);
            if (strlen($rakam) >= 3) {
                $w->orWhere('og.cep_telefon', 'like', '%'.$rakam.'%');
            }
        });
    }

    /**
     * Satırlara ön görüşme randevusunun tarih/saat/oda bilgisini ekleyip sunar. Randevu
     * ikinci sorguda (sayfadaki ≤100 görüşme için) — büyük randevular tablosuna JOIN/GROUP BY yok.
     */
    private function sun($satirlar, array $ctx): array
    {
        $randevular = $satirlar->isEmpty() ? collect() : DB::table('randevular as r')
            ->leftJoin('randevu_hizmetler as rh', 'rh.randevu_id', '=', 'r.id')
            ->whereIn('r.on_gorusme_id', $satirlar->pluck('id')->all())
            ->orderBy('r.id')
            ->get(['r.on_gorusme_id', 'r.id', 'r.tarih', 'r.saat', 'rh.oda_id'])
            ->keyBy('on_gorusme_id'); // birden çok (eski veri) → son randevu

        $paketler = $this->paketOzetleri($satirlar->pluck('paket_id')->filter()->unique()->all());

        return $satirlar->map(function ($r) use ($randevular, $ctx, $paketler) {
            $r->paket_ozeti = $r->paket_id ? ($paketler[(int) $r->paket_id] ?? null) : null;
            $rv = $randevular[$r->id] ?? null;
            $r->randevu_id = $rv?->id;
            $r->randevu_tarih = $rv?->tarih;
            $r->randevu_saat = $rv?->saat;
            $r->oda_id = $rv?->oda_id;

            return OnGorusmeSunumu::satir($r, $ctx);
        })->values()->all();
    }

    /**
     * Sayfadaki paketlerin satis varsayilanlari (tek sorgu, satir basina yok). Mobil on gorusme satis penceresi
     * fiyat / seans (bolge basina) / sinirsiz / seans araligini buradan okur (eski ongorusmegetir $with['paket']).
     * Web bu alanlari yok sayar.
     *
     * @param  int[]  $paketIdler
     * @return array<int,array<string,mixed>>
     */
    private function paketOzetleri(array $paketIdler): array
    {
        if (! $paketIdler) {
            return [];
        }

        return Paketler::with('hizmetler.hizmet')->whereIn('id', $paketIdler)->get()
            ->mapWithKeys(function (Paketler $p) {
                $s = PaketSunumu::paket($p);

                return [(int) $p->id => [
                    'fiyat' => $s['fiyat'], 'miktar' => $s['miktar'], 'satis_seans' => $s['satis_seans'],
                    'seans_araligi' => $p->seans_araligi !== null && $p->seans_araligi !== '' ? (int) $p->seans_araligi : null,
                    'sinirsiz' => $s['sinirsiz'], 'gecerlilik_sayi' => $s['gecerlilik_sayi'], 'gecerlilik_birim' => $s['gecerlilik_birim'],
                    'gecerlilik_metni' => $s['gecerlilik_metni'], 'gunluk_limit' => $s['gunluk_limit'],
                    'hizmetler' => array_map(fn ($h) => ['hizmet_id' => $h['hizmet_id'], 'hizmet_adi' => $h['hizmet_adi'], 'seans' => $h['seans'], 'fiyat' => $h['fiyat']], $s['hizmetler']),
                ]];
            })->all();
    }
}
