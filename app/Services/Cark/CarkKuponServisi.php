<?php

namespace App\Services\Cark;

use App\Domain\Cark\DilimTipi;
use App\Domain\Cark\KuponDurumu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\Yetki\KapsamServisi;
use App\Services\Yetki\SubeGrubu;
use App\Support\AktiviteLog;
use App\Support\KvkkMaske;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ödül KUPONLARI (carkifelek_odulleri: çark, puan ödülü, anket/yorum/reklam hediyeleri) —
 * liste + doğrulama + kullanıldı/geri al + müşterinin geçerli kuponları. Eski web
 * carkKazananlar/carkKuponDogrula/carkKuponKullan, mobil carkKazananlarApi/carkKuponDogrulaApi/
 * carkKuponKullanApi/carkKuponlariApi'nin tek karşılığı.
 *
 * Çoklu şube: kupon yalnız geçerli olduğu şube grubunda doğrulanır/işaretlenir (SubeGrubu).
 */
class CarkKuponServisi
{
    private const TABLO = 'carkifelek_odulleri';

    /** ListeIstegi sıralama beyaz listesi: alan => SQL ifadesi. */
    public const SIRALAMA = [
        'islem'      => 'COALESCE(o.kullanim_tarihi, o.created_at)',
        'kazanma'    => 'o.created_at',
        'gecerlilik' => 'o.gecerlilik_tarihi',
        'kod'        => 'o.kod',
    ];

    public function __construct(
        private SubeGrubu $subeler,
        private KapsamServisi $kapsam,
    ) {}

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $l
     * @return array{data: array, sayaclar: array<string,int>, current_page:int, last_page:int, per_page:int, total:int}
     */
    public function liste(int $salonId, string $durum, array $l, bool $telefonGor): array
    {
        $bugun = now()->toDateString();
        $q = DB::table(self::TABLO.' as o')->leftJoin('users as u', 'u.id', '=', 'o.user_id')->where('o.salon_id', $salonId);
        if ($desen = ListeIstegi::like($l['ara'])) {
            $q->where(fn (Builder $w) => $w->where('o.kod', 'like', $desen)->orWhere('o.baslik', 'like', $desen)
                ->orWhere('u.name', 'like', $desen)->orWhere('u.cep_telefon', 'like', $desen));
        }
        $sayaclar = $this->sayaclar(clone $q, $bugun);
        $this->durumFiltresi($q, KuponDurumu::filtre($durum), $bugun);

        $toplam = (clone $q)->count();
        $satirlar = $q->orderByRaw((self::SIRALAMA[$l['sirala']] ?? self::SIRALAMA['islem']).' '.($l['yon'] === 'asc' ? 'asc' : 'desc'))
            ->orderByDesc('o.id')
            ->forPage($l['page'], $l['per_page'])
            ->get(['o.*', 'u.name as musteri_ad', 'u.cep_telefon as musteri_tel']);

        return ['data' => $satirlar->map(fn ($o) => $this->sun($o, $bugun, $telefonGor))->all(), 'sayaclar' => $sayaclar]
            + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }

    /** Müşterinin getirdiği kodu bu şubede doğrular (kullanmaz). */
    public function dogrula(int $salonId, int $yetkiliId, string $kod, bool $telefonGor): array
    {
        $kod = strtoupper(trim($kod));
        $o = DB::table(self::TABLO.' as o')->leftJoin('users as u', 'u.id', '=', 'o.user_id')->where('o.kod', $kod)
            ->first(['o.*', 'u.name as musteri_ad', 'u.cep_telefon as musteri_tel']);
        $this->subedeGecerli($o, $salonId, $yetkiliId);

        return $this->sun($o, now()->toDateString(), $telefonGor);
    }

    /**
     * Kullanıldı işaretle (kullan=true) ya da kullanımı geri al. Satır kilidi: çift tıklama /
     * iki kasada aynı anda okutma kuponu iki kez yakmaz.
     */
    public function isaretle(int $salonId, int $yetkiliId, int $id, bool $kullan, bool $telefonGor): array
    {
        $o = DB::transaction(function () use ($salonId, $yetkiliId, $id, $kullan) {
            $o = DB::table(self::TABLO)->where('id', $id)->lockForUpdate()->first();
            $this->subedeGecerli($o, $salonId, $yetkiliId);
            $durum = KuponDurumu::hesapla((bool) $o->kullanildi, $o->gecerlilik_tarihi, now()->toDateString());
            if ($kullan && $durum !== KuponDurumu::GECERLI) {
                throw new CakismaException($durum === KuponDurumu::KULLANILDI ? 'Kupon zaten kullanılmış.' : 'Kuponun süresi dolmuş; kullanılamaz.');
            }
            if (! $kullan && $durum !== KuponDurumu::KULLANILDI) {
                throw new CakismaException('Kupon kullanılmamış; geri alınacak bir işlem yok.');
            }
            DB::table(self::TABLO)->where('id', $id)->update([
                'kullanildi' => (int) $kullan, 'kullanim_tarihi' => $kullan ? now() : null, 'updated_at' => now(),
            ]);

            return $o;
        });

        $musteri = DB::table('users')->where('id', $o->user_id)->value('name') ?: 'Müşteri #'.$o->user_id;
        AktiviteLog::yaz($salonId, $yetkiliId, $kullan ? 'cark_kupon_kullan' : 'cark_kupon_geri_al', 'cark_kupon', $id,
            $o->kod.' — '.$musteri, $kullan ? 'Çark kuponu kullanıldı olarak işaretlendi' : 'Çark kuponu kullanımı geri alındı',
            ['baslik' => $o->baslik, 'tip' => $o->tip, 'deger' => $o->deger, 'user_id' => $o->user_id]);

        $guncel = DB::table(self::TABLO.' as o')->leftJoin('users as u', 'u.id', '=', 'o.user_id')->where('o.id', $id)
            ->first(['o.*', 'u.name as musteri_ad', 'u.cep_telefon as musteri_tel']);

        return $this->sun($guncel, now()->toDateString(), $telefonGor);
    }

    /**
     * Müşterinin bu şubede GEÇERLİ, kullanılmamış İNDİRİM kuponları (tahsilat ekranı: yoksa alan
     * gizli). Mobil carkKuponlariApi / web kullaniciKuponlari ile aynı filtre.
     */
    public function musteriKuponlari(int $salonId, int $userId): array
    {
        $bugun = now()->toDateString();

        return DB::table(self::TABLO)->where('user_id', $userId)
            ->whereIn('salon_id', $this->subeler->kardesler($salonId))
            ->where('kullanildi', 0)
            ->whereIn('tip', [DilimTipi::HIZMET, DilimTipi::URUN, DilimTipi::PAKET])
            ->where(fn (Builder $w) => $w->whereNull('gecerlilik_tarihi')->orWhere('gecerlilik_tarihi', '>=', $bugun))
            ->orderByDesc('id')->get()
            ->filter(fn ($k) => in_array($salonId, $this->subeler->kuponSubeleri($k->gecerli_salonlar, (int) $k->salon_id), true))
            ->map(fn ($k) => [
                'id' => (int) $k->id, 'kod' => $k->kod, 'tip' => $k->tip, 'baslik' => $k->baslik,
                'deger' => $k->deger !== null ? (float) $k->deger : null,
                'indirim_tipi' => DilimTipi::birim($k->tip, $k->indirim_tipi ?? null),
                'gecerlilik_tarihi' => $k->gecerlilik_tarihi,
            ])->values()->all();
    }

    // ───────────────────────────── yardımcılar ─────────────────────────────

    /**
     * Kupon yoksa 404. Var ama bu şubenin grubunda değilse: kullanıcının erişebildiği bir şubeye
     * aitse açıklayıcı 422, değilse 404 (başka işletmenin kuponunun varlığı sızdırılmaz).
     */
    private function subedeGecerli(?object $o, int $salonId, int $yetkiliId): void
    {
        if (! $o) {
            throw new BulunamadiException('Bu kod sisteme kayıtlı değil.');
        }
        if (in_array($salonId, $this->subeler->kuponSubeleri($o->gecerli_salonlar ?? null, (int) $o->salon_id), true)) {
            return;
        }
        if (in_array((int) $o->salon_id, $this->kapsam->kullaniciSalonlari($yetkiliId), true)) {
            throw new GecersizIslemException('Bu kupon bu şubede geçerli değil.');
        }
        throw new BulunamadiException('Bu kod sisteme kayıtlı değil.');
    }

    private function durumFiltresi(Builder $q, string $durum, string $bugun): void
    {
        match ($durum) {
            KuponDurumu::KULLANILDI => $q->where('o.kullanildi', 1),
            KuponDurumu::GECERLI    => $q->where('o.kullanildi', 0)
                ->where(fn (Builder $w) => $w->whereNull('o.gecerlilik_tarihi')->orWhere('o.gecerlilik_tarihi', '>=', $bugun)),
            KuponDurumu::SURE_DOLDU => $q->where('o.kullanildi', 0)->whereNotNull('o.gecerlilik_tarihi')->where('o.gecerlilik_tarihi', '<', $bugun),
            default                 => null,
        };
    }

    /** Filtre sekmesi sayaçları (arama uygulanmış, durum filtresi uygulanmamış) — tek sorgu. */
    private function sayaclar(Builder $q, string $bugun): array
    {
        $s = $q->selectRaw('COUNT(*) as tumu,
                SUM(o.kullanildi = 1) as kullanildi,
                SUM(o.kullanildi = 0 AND (o.gecerlilik_tarihi IS NULL OR o.gecerlilik_tarihi >= ?)) as gecerli,
                SUM(o.kullanildi = 0 AND o.gecerlilik_tarihi < ?) as sure_doldu', [$bugun, $bugun])->first();

        return [
            KuponDurumu::TUMU       => (int) $s->tumu,
            KuponDurumu::GECERLI    => (int) $s->gecerli,
            KuponDurumu::KULLANILDI => (int) $s->kullanildi,
            KuponDurumu::SURE_DOLDU => (int) $s->sure_doldu,
        ];
    }

    /** Kupon JSON sözleşmesi (liste satırı = doğrulama sonucu; web + Flutter aynı alanlar). */
    private function sun(object $o, string $bugun, bool $telefonGor): array
    {
        $durum = KuponDurumu::hesapla((bool) $o->kullanildi, $o->gecerlilik_tarihi, $bugun);
        $tel = $o->musteri_tel ?? null;

        return [
            'id'                => (int) $o->id,
            'kod'               => (string) $o->kod,
            'baslik'            => (string) ($o->baslik ?: DilimTipi::odulMetni((string) $o->tip, $o->deger !== null ? (float) $o->deger : null, $o->indirim_tipi ?? null)),
            'tip'               => (string) $o->tip,
            'deger'             => $o->deger !== null ? (float) $o->deger : null,
            'indirim_tipi'      => DilimTipi::birim((string) $o->tip, $o->indirim_tipi ?? null),
            'musteri'           => [
                'id'      => (int) $o->user_id,
                'ad'      => trim((string) ($o->musteri_ad ?? '')) ?: 'Müşteri #'.$o->user_id,
                'telefon' => $tel ? ($telefonGor ? $tel : KvkkMaske::telefon($tel)) : null,
            ],
            'kazanma_tarihi'    => $o->created_at,
            'gecerlilik_tarihi' => $o->gecerlilik_tarihi ? substr($o->gecerlilik_tarihi, 0, 10) : null,
            'kullanildi'        => (int) $o->kullanildi,
            'kullanim_tarihi'   => $o->kullanim_tarihi,
            'durum'             => $durum,
            'durum_ad'          => KuponDurumu::etiket($durum),
        ];
    }
}
