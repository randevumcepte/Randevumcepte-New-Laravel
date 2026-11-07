<?php

namespace App\Services\Form;

use App\Domain\Form\ArsivDurum;
use App\Support\EskiPanelLinki;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * Arşiv (form / sözleşme / harici belge) LİSTE servisi — TEK KAYNAK (web v2 Form
 * Yönetimi + müşteri detayı "Sözleşmeler/Belgeler" + Flutter arsiv_kart_liste).
 *
 * Eski kaynak: StoreAdminController@arsiv_liste_getir (aynı sorgunun 5 kopyası, HTML
 * SQL içinde üretiliyordu; her işlemde 5 liste birden dönüyordu) + ApiController@arsivyukle.
 * Burada TEK sorgu + sekme filtresi; satırın tür/durum/aksiyon kararları saf
 * ArsivDurum'dan gelir. Hiçbir şey YAZMAZ.
 *
 * Sekmeler (bilinçli düzeltme): bekleyen SÖZLEŞME eskide web'de "Harici Belgeler"
 * sekmesine, Flutter'da hiçbir sekmeye düşmüyordu; burada "Beklenenler"dedir.
 */
class ArsivListeServisi
{
    /** İzinli sıralama alanları → SQL ifadesi. */
    private const SIRALAMA = [
        'tarih'   => 'a.id',
        'musteri' => 'u.name',
        'baslik'  => 'baslik',
    ];

    /**
     * @param array{gonder:bool} $yetkiler
     */
    public function liste(int|array $salonId, string $sekme, ?int $musteriId, ListeIstegi $istek, array $yetkiler): array
    {
        $sekme = ArsivDurum::sekme($sekme);
        $taban = $this->tabanSorgu($salonId, $musteriId, $istek->likeDeseni());

        $ozet = $this->ozet(clone $taban);
        $sorgu = $this->sekmeFiltresi(clone $taban, $sekme);
        $toplam = (clone $sorgu)->count('a.id');

        $siralaSql = self::SIRALAMA[$istek->sirala] ?? 'a.id';
        $satirlar = $sorgu
            ->orderByRaw($siralaSql.' '.($istek->yon === 'asc' ? 'asc' : 'desc'))
            ->orderByDesc('a.id')
            ->forPage($istek->sayfa, $istek->sayfaBoyu)
            ->get([
                'a.id', 'a.salon_id', 'a.user_id', 'a.form_id', 'a.personel_id', 'a.durum', 'a.cevapladi', 'a.cevapladi2',
                'a.is_sozlesme', 'a.harici_belge', 'a.sozlesme_adi', 'a.uzanti', 'a.created_at',
                'u.name as musteri_adi', 'p.personel_adi', 'f.form_adi',
                DB::raw(self::BASLIK_SQL.' as baslik'),
            ])
            ->map(fn ($s) => $this->donustur($s, $yetkiler))
            ->all();

        return ['data' => $satirlar, 'sekme' => $sekme, 'ozet' => $ozet] + ListeIstegi::meta($istek->sayfa, $istek->sayfaBoyu, $toplam);
    }

    /**
     * Müşteri uygulaması: kendi belgeleri, beyaz liste. Panel linkleri ve aksiyonlar yoktur.
     *
     * @param array<int,int> $salonIdler
     */
    public function musteriListesi(array $salonIdler, int $userId, string $sekme, ListeIstegi $istek): array
    {
        $sayfa = $this->liste($salonIdler, $sekme, $userId, $istek, ['gonder' => false]);
        $alanlar = array_flip(['id', 'tarih', 'created_at', 'baslik', 'tur', 'durum', 'cevapladi', 'cevapladi2',
            'is_sozlesme', 'harici_belge', 'sozlesme_adi', 'uzanti', 'musteri', 'personel', 'form']);
        $sayfa['data'] = array_map(fn (array $satir) => array_intersect_key($satir, $alanlar), $sayfa['data']);

        return $sayfa;
    }

    /** Tek satır (işlem sonrası istemci satırı tazelemek isterse). */
    public function satir(int $arsivId, array $yetkiler): ?array
    {
        $s = $this->tabanSorgu(null, null, null)->where('a.id', $arsivId)->first([
            'a.id', 'a.salon_id', 'a.user_id', 'a.form_id', 'a.personel_id', 'a.durum', 'a.cevapladi', 'a.cevapladi2',
            'a.is_sozlesme', 'a.harici_belge', 'a.sozlesme_adi', 'a.uzanti', 'a.created_at',
            'u.name as musteri_adi', 'p.personel_adi', 'f.form_adi', DB::raw(self::BASLIK_SQL.' as baslik'),
        ]);

        return $s ? $this->donustur($s, $yetkiler) : null;
    }

    // ---------------------------------------------------------------------

    /** Başlık: form adı; form_id=0 (harici/sözleşme) ise harici_belge. */
    private const BASLIK_SQL = "CASE WHEN a.form_id != 0 THEN f.form_adi ELSE a.harici_belge END";

    private function tabanSorgu(int|array|null $salonId, ?int $musteriId, ?string $like)
    {
        return DB::table('arsiv as a')
            ->leftJoin('formtaslaklari as f', 'f.id', '=', 'a.form_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 'a.personel_id')
            ->when($salonId !== null, fn ($q) => $q->whereIn('a.salon_id', (array) $salonId))
            ->when($musteriId, fn ($q) => $q->where('a.user_id', $musteriId))
            ->when($like, fn ($q) => $q->where(function ($w) use ($like) {
                $w->where('u.name', 'like', $like)
                    ->orWhere('f.form_adi', 'like', $like)
                    ->orWhere('a.harici_belge', 'like', $like);
            }));
    }

    /** SQL karşılığı — ArsivDurum::tur/durum kurallarıyla aynı ayrım. */
    private function sekmeFiltresi($q, string $sekme)
    {
        $harici = fn ($w) => $w->where('a.form_id', 0)->where(fn ($x) => $x->whereNull('a.is_sozlesme')->orWhere('a.is_sozlesme', '!=', 1));

        return match ($sekme) {
            'onayli'   => $q->where('a.durum', 1),
            'iptal'    => $q->where('a.durum', 0),
            'beklenen' => $q->whereNull('a.durum')->where(fn ($w) => $w->where('a.form_id', '!=', 0)->orWhere('a.is_sozlesme', 1)),
            'harici'   => $q->whereNull('a.durum')->where($harici),
            default    => $q,
        };
    }

    /** Sekme sayaçları — tek sorguda. */
    private function ozet($q): array
    {
        $r = $q->selectRaw('COUNT(*) as tumu')
            ->selectRaw('SUM(a.durum = 1) as onayli')
            ->selectRaw('SUM(a.durum = 0) as iptal')
            ->selectRaw('SUM(a.durum IS NULL AND (a.form_id != 0 OR a.is_sozlesme = 1)) as beklenen')
            ->selectRaw('SUM(a.durum IS NULL AND a.form_id = 0 AND COALESCE(a.is_sozlesme, 0) != 1) as harici')
            ->first();

        return array_map(fn ($a) => (int) ($r->{$a} ?? 0), array_combine(ArsivDurum::SEKMELER, ArsivDurum::SEKMELER));
    }

    /**
     * Satır sözleşmesi. Ham alanlar (durum, cevapladi*, uzanti, harici_belge) ve iç içe
     * form{form_adi} / musteri{name} / personel{} Flutter Arsiv.fromJson uyumu için
     * korunur; sağlık cevapları vb. hassas kolonlar listeye KONMAZ (veri minimizasyonu).
     */
    private function donustur(object $s, array $yetkiler): array
    {
        $ham = (array) $s;
        $tur = ArsivDurum::tur($ham);

        return [
            'id'           => (int) $s->id,
            'tarih'        => $s->created_at ? date('d.m.Y', strtotime($s->created_at)) : null,
            'created_at'   => $s->created_at,
            'baslik'       => $s->baslik ?? ($tur === ArsivDurum::TUR_SOZLESME ? 'Hizmet Sözleşmesi' : '-'),
            'tur'          => $tur,
            'durum'        => $s->durum === null ? null : (int) $s->durum,
            'cevapladi'    => $s->cevapladi === null ? null : (int) $s->cevapladi,
            'cevapladi2'   => $s->cevapladi2 === null ? null : (int) $s->cevapladi2,
            'is_sozlesme'  => (int) ($s->is_sozlesme ?? 0),
            'harici_belge' => $s->harici_belge,
            'sozlesme_adi' => $s->sozlesme_adi ?? null,
            'uzanti'       => $s->uzanti,
            'durum_bilgi'  => ArsivDurum::durum($ham),
            'aksiyonlar'   => ArsivDurum::aksiyonlar($ham, $yetkiler),
            'musteri'      => ['id' => $s->user_id ? (int) $s->user_id : null, 'name' => $s->musteri_adi ?? '-'],
            'personel'     => $s->personel_id ? ['id' => (int) $s->personel_id, 'personel_adi' => $s->personel_adi] : null,
            'form'         => ['id' => (int) $s->form_id, 'form_adi' => $s->form_adi ?? ($s->baslik ?? '-')],
            'belge'        => $this->belgeLinkleri($s, $tur),
        ];
    }

    /**
     * Görüntüle / indir / yazdır linkleri. PDF üretimi (form başına eski blade
     * şablonları + dompdf) şimdilik ESKİ panelde kalır; oturumlu eski uçlara gidilir.
     * Harici belge: dosyanın kendisi.
     */
    private function belgeLinkleri(object $s, string $tur): array
    {
        $sorgu = ['arsivid' => (int) $s->id, 'sube' => (int) $s->salon_id];
        $goruntule = $tur === ArsivDurum::TUR_HARICI && $s->uzanti
            ? EskiPanelLinki::url($s->uzanti)
            : EskiPanelLinki::url('/isletmeyonetim/formgoster', $sorgu);

        return [
            'goruntule_url' => $goruntule,
            'indir_url'     => EskiPanelLinki::url('/isletmeyonetim/formindir', $sorgu),
            'yazdir_url'    => $goruntule,
        ];
    }
}
