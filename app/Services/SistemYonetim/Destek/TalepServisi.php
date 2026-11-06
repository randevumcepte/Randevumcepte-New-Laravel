<?php

namespace App\Services\SistemYonetim\Destek;

use App\Support\SorguSayfasi;
use App\Domain\SistemYonetim\TalepDurumu;
use App\Domain\SistemYonetim\TalepGecisi;
use App\Domain\SistemYonetim\TalepKategorisi;
use App\Domain\SistemYonetim\TalepNumarasi;
use App\Domain\SistemYonetim\TalepOnceligi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use App\Support\ListeIstegi;
use App\Support\ZamanliKayit;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/** Destek talepleri: liste, detay, konusma ve islemler. Eski panelle ayni tablolar ve denetim adlari. */
class TalepServisi
{
    private const TABLO = 'sistemyonetim_destek_talepleri';

    private const MESAJ_TABLOSU = 'sistemyonetim_destek_mesajlari';

    private const NUMARA_DENEME = 5;

    /** Dogrudan kolona siralananlar; durum ve oncelik is sirasina gore. */
    private const SIRALAMA = ['numara' => 'numara', 'konu' => 'konu', 'salon' => 'salon_adi', 'kategori' => 'kategori', 'atanan' => 'atanan_user_name', 'tarih' => 'id'];

    public const CSV_BASLIKLARI = ['Numara', 'Salon', 'Konu', 'Kategori', 'Öncelik', 'Durum', 'Atanan', 'Açan', 'Açılış', 'İlk Yanıt', 'Çözüm'];

    public function __construct(private TalepNumarasi $numara) {}

    /** @return list<string> */
    public static function siralanabilir(): array
    {
        return [...array_keys(self::SIRALAMA), 'durum', 'oncelik'];
    }

    public function secenekler(): array
    {
        return [
            'durumlar'            => TalepDurumu::secenekler(),
            'durum_filtreleri'    => TalepDurumu::filtreSecenekleri(),
            'varsayilan_filtre'   => TalepDurumu::FILTRE_ACIK,
            'oncelikler'          => TalepOnceligi::secenekler(),
            'varsayilan_oncelik'  => TalepOnceligi::Orta->value,
            'kategoriler'         => TalepKategorisi::secenekler(),
            'varsayilan_kategori' => TalepKategorisi::Diger->value,
            'atanan_filtreleri'   => [['kod' => 'bana', 'etiket' => 'Bana atananlar'], ['kod' => 'atanmamis', 'etiket' => 'Atanmamış']],
            'ekip'                => DB::table('sistemyoneticileri')->where('aktif', 1)->orderBy('name')->get(['id', 'name as ad'])->all(),
        ];
    }

    /** @param array{durum?:?string,oncelik?:?string,atanan?:?string} $filtre */
    public function listele(array $liste, array $filtre, SistemYoneticisi $yonetici): array
    {
        $q = $this->filtreli($filtre, $yonetici);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('konu', 'like', $like)->orWhere('numara', 'like', $like)->orWhere('salon_adi', 'like', $like));
        }

        return SorguSayfasi::uret($q, $liste, [], fn (Builder $s) => $this->sirala($s, $liste), fn ($t) => $this->satir($t));
    }

    public function detay(int $id): array
    {
        $t = $this->bul($id);

        return [
            'talep' => $this->satir($t) + [
                'aciklama'            => $t->aciklama,
                'iletisim_ad'         => $t->iletisim_ad,
                'iletisim_telefon'    => $t->iletisim_telefon,
                'iletisim_email'      => $t->iletisim_email,
                'olusturan_user_name' => $t->olusturan_user_name,
                'ilk_yanit_tarihi'    => $t->ilk_yanit_tarihi,
                'cozumlenme_tarihi'   => $t->cozumlenme_tarihi,
                'kapanis_tarihi'      => $t->kapanis_tarihi,
            ],
            'mesajlar' => DB::table(self::MESAJ_TABLOSU)->where('ticket_id', $id)->orderBy('id')->get()->map(fn ($m) => [
                'id'        => (int) $m->id,
                'user_name' => $m->user_name,
                'user_tipi' => $m->user_tipi,
                'mesaj'     => $m->mesaj,
                'ic_not'    => (bool) $m->ic_not,
                'tarih'     => $m->created_at,
            ])->all(),
            'salon' => $t->salon_id ? DB::table('salonlar')->where('id', $t->salon_id)->first(['id', 'salon_adi']) : null,
        ];
    }

    /** @param array{konu:string,kategori:string,oncelik:string,aciklama?:?string,iletisim_ad?:?string,iletisim_telefon?:?string,iletisim_email?:?string} $g */
    public function olustur(array $g, ?object $salon, SistemYoneticisi $yonetici): array
    {
        $alanlar = [
            'salon_id'            => $salon?->id,
            'salon_adi'           => $salon?->salon_adi,
            'iletisim_ad'         => $g['iletisim_ad'] ?? null,
            'iletisim_telefon'    => $g['iletisim_telefon'] ?? null,
            'iletisim_email'      => $g['iletisim_email'] ?? null,
            'konu'                => $g['konu'],
            'aciklama'            => $g['aciklama'] ?? null,
            'kategori'            => $g['kategori'],
            'oncelik'             => $g['oncelik'],
            'durum'               => TalepDurumu::Acik->value,
            'olusturan_user_id'   => $yonetici->id,
            'olusturan_user_name' => $yonetici->name,
        ];

        [$id, $numara] = DB::transaction(function () use ($alanlar, $g, $yonetici) {
            $kayit = $this->numaraylaEkle($alanlar);
            if (! empty($g['aciklama'])) {
                $this->mesajEkle($kayit[0], $g['aciklama'], false, $yonetici);
            }

            return $kayit;
        });

        Denetim::yaz('ticket_olustur', 'ticket', $id, "{$numara} | {$g['konu']}");

        return ['id' => $id, 'numara' => $numara];
    }

    public function yanitla(int $id, string $mesaj, bool $icNot, SistemYoneticisi $yonetici): array
    {
        $t = $this->bul($id);
        DB::transaction(function () use ($t, $mesaj, $icNot, $yonetici) {
            $this->mesajEkle((int) $t->id, $mesaj, $icNot, $yonetici);
            $this->guncelle((int) $t->id, TalepGecisi::yanit($t->durum, $t->ilk_yanit_tarihi, now()->toDateTimeString()));
        });
        Denetim::yaz('ticket_yanit', 'ticket', $id, $t->numara);

        return $this->detay($id);
    }

    public function durumDegistir(int $id, TalepDurumu $yeni): array
    {
        $t = $this->bul($id);
        $this->guncelle($id, TalepGecisi::durum($yeni, $t->cozumlenme_tarihi, $t->kapanis_tarihi, now()->toDateTimeString()));
        Denetim::yaz('ticket_durum', 'ticket', $id, $t->numara, "{$t->durum} → {$yeni->value}");

        return $this->detay($id);
    }

    /** Yalniz aktif ekip uyesine atanir; null atamayi kaldirir. */
    public function ata(int $id, ?int $kullaniciId): array
    {
        $t = $this->bul($id);
        $kullanici = $kullaniciId ? DB::table('sistemyoneticileri')->where('id', $kullaniciId)->where('aktif', 1)->first(['id', 'name']) : null;
        if ($kullaniciId && ! $kullanici) {
            throw new BulunamadiException('Atanacak kullanıcı bulunamadı');
        }

        $this->guncelle($id, ['atanan_user_id' => $kullanici?->id, 'atanan_user_name' => $kullanici?->name]);
        Denetim::yaz('ticket_ata', 'ticket', $id, $t->numara, $t->atanan_user_name.' → '.($kullanici?->name ?: 'atanmamış'));

        return $this->detay($id);
    }

    public function oncelikDegistir(int $id, TalepOnceligi $yeni): array
    {
        $t = $this->bul($id);
        $this->guncelle($id, ['oncelik' => $yeni->value]);
        Denetim::yaz('ticket_oncelik', 'ticket', $id, $t->numara, "{$t->oncelik} → {$yeni->value}");

        return $this->detay($id);
    }

    /** Tum talepler, yeniden eskiye; satirlar akarken okunur. */
    public function csvSatirlari(): LazyCollection
    {
        $tablo = fn () => DB::table(self::TABLO);
        Denetim::yaz('csv_export', 'ticket', null, 'Ticket listesi ('.$tablo()->count().')');

        return $tablo()->lazyByIdDesc(500)->map(fn ($t) => [
            $t->numara, $t->salon_adi, $t->konu, $t->kategori, $t->oncelik, $t->durum, $t->atanan_user_name, $t->olusturan_user_name,
            $this->csvTarih($t->created_at), $this->csvTarih($t->ilk_yanit_tarihi), $this->csvTarih($t->cozumlenme_tarihi),
        ]);
    }

    private function filtreli(array $f, SistemYoneticisi $yonetici): Builder
    {
        $q = DB::table(self::TABLO);
        $durum = $f['durum'] ?? TalepDurumu::FILTRE_ACIK;
        if ($durum === TalepDurumu::FILTRE_ACIK) {
            $q->whereIn('durum', TalepDurumu::acikKapsam());
        } elseif ($durum !== TalepDurumu::FILTRE_HEPSI) {
            $q->where('durum', $durum);
        }

        $atanan = $f['atanan'] ?? null;
        match (true) {
            $atanan === null        => null,
            $atanan === 'bana'      => $q->where('atanan_user_id', $yonetici->id),
            $atanan === 'atanmamis' => $q->whereNull('atanan_user_id'),
            default                 => $q->where('atanan_user_id', (int) $atanan),
        };

        return $q->when($f['oncelik'] ?? null, fn ($w, $v) => $w->where('oncelik', $v));
    }

    /** Varsayilan: durum sirasi, sonra aciliyet, sonra yeniden eskiye. */
    private function sirala(Builder $q, array $liste): void
    {
        $durumSirasi = array_column(TalepDurumu::cases(), 'value');
        $alan = $liste['sirala'] ?? '';
        match (true) {
            isset(self::SIRALAMA[$alan]) => $q->orderBy(self::SIRALAMA[$alan], $liste['yon']),
            $alan === 'durum'   => $this->kodSirasiyla($q, 'durum', $durumSirasi, $liste['yon']),
            $alan === 'oncelik' => $this->kodSirasiyla($q, 'oncelik', TalepOnceligi::aciliyetSirasi(), $liste['yon']),
            default => $this->kodSirasiyla($this->kodSirasiyla($q, 'durum', $durumSirasi, 'asc'), 'oncelik', TalepOnceligi::aciliyetSirasi(), 'asc'),
        };
        $q->orderByDesc('id');
    }

    /** @param list<string> $kodlar */
    private function kodSirasiyla(Builder $q, string $kolon, array $kodlar, string $yon): Builder
    {
        $dallar = implode(' ', array_fill(0, count($kodlar), 'WHEN ? THEN ?'));
        $baglar = [];
        foreach ($kodlar as $sira => $kod) {
            array_push($baglar, $kod, $sira);
        }

        return $q->orderByRaw("CASE {$kolon} {$dallar} ELSE ".count($kodlar).' END '.($yon === 'desc' ? 'DESC' : 'ASC'), $baglar);
    }

    private function satir(object $t): array
    {
        return [
            'id'               => (int) $t->id,
            'numara'           => $t->numara,
            'konu'             => $t->konu,
            'salon_id'         => $t->salon_id ? (int) $t->salon_id : null,
            'salon_adi'        => $t->salon_adi,
            'kategori'         => TalepKategorisi::rozet($t->kategori),
            'oncelik'          => TalepOnceligi::rozet($t->oncelik),
            'durum'            => TalepDurumu::rozet($t->durum),
            'atanan_user_id'   => $t->atanan_user_id ? (int) $t->atanan_user_id : null,
            'atanan_user_name' => $t->atanan_user_name,
            'acilis'           => $t->created_at,
            'acilis_goreli'    => $t->created_at ? Carbon::parse($t->created_at)->locale('tr')->diffForHumans() : null,
        ];
    }

    private function bul(int $id): object
    {
        return DB::table(self::TABLO)->where('id', $id)->first() ?? throw new BulunamadiException('Talep bulunamadı');
    }

    private function guncelle(int $id, array $alanlar): void
    {
        DB::table(self::TABLO)->where('id', $id)->update($alanlar + ['updated_at' => now()]);
    }

    private function mesajEkle(int $talepId, string $mesaj, bool $icNot, SistemYoneticisi $yonetici): void
    {
        ZamanliKayit::ekle(self::MESAJ_TABLOSU, [
            'ticket_id' => $talepId,
            'user_id'   => $yonetici->id,
            'user_name' => $yonetici->name,
            'user_tipi' => 'ekip',
            'mesaj'     => $mesaj,
            'ic_not'    => $icNot ? 1 : 0,
        ]);
    }

    /**
     * numara kolonu unique: cakisan numara yenisiyle denenir.
     *
     * @return array{0:int,1:string} [id, numara]
     */
    private function numaraylaEkle(array $alanlar): array
    {
        for ($deneme = 0; $deneme < self::NUMARA_DENEME; $deneme++) {
            $numara = $this->numara->uret(now());
            try {
                return [ZamanliKayit::ekle(self::TABLO, ['numara' => $numara] + $alanlar), $numara];
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new CakismaException('Talep numarası üretilemedi, lütfen tekrar deneyin');
    }

    private function csvTarih(?string $tarih): string
    {
        return $tarih ? Carbon::parse($tarih)->format('d.m.Y H:i') : '';
    }
}
