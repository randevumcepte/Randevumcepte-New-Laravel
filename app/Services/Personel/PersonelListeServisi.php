<?php

namespace App\Services\Personel;

use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * Personel LISTE servisi (salt-okuma).
 *
 * Eski ApiController@personelgetir ile ayni filtre/sira: arsivliler elenir,
 * takvim_sirasi + id'ye gore siralanir. DTO = PersonelOkumaServisi ust-kumesi
 * (tek kaynak; kod tekrari yok).
 *
 * Iki kullanim (web + Flutter ayni uc):
 *  - sayfali  : eski /personelgetir (liste ekrani)
 *  - tumu=true: eski /personeller/{salon} (dropdown'lar ve ozet kartlari; Flutter'da 10+ ekran)
 * Her cevapta salonun tum (arsivsiz) personeli uzerinden 'ozet' sayilari doner —
 * aramadan/sayfadan bagimsiz (eski web + Flutter istatistik kartlari).
 */
class PersonelListeServisi
{
    public function __construct(private PersonelOkumaServisi $okuma) {}

    /**
     * @return array{data: array, current_page:int, last_page:int, per_page:int, total:int,
     *               ozet: array{toplam:int, aktif:int, takvimde:int}}
     */
    /** Tablo sıralama alanı → kolon (beyaz liste; controller da aynı anahtarları kabul eder). */
    public const SIRALAMA = [
        'ad'         => 'sp.personel_adi',
        'telefon'    => 'sp.cep_telefon',
        'hesap_turu' => 'r.id',
        'maas'       => 'sp.maas',
        'takvimde'   => 'sp.takvimde_gorunsun',
        'durum'      => 'sp.aktif',
    ];

    public function listele(int $salonId, ?string $baslik, array $gor = [], int $perPage = 10, bool $tumu = false, ?string $sirala = null, string $yon = 'asc'): array
    {
        $sorgu = $this->okuma->temelSorgu()
            ->where('sp.salon_id', $salonId)
            ->where(fn ($q) => $this->arsivsiz($q, 'sp.arsivli'))
            ->groupBy('sp.id');

        // Sıralama: varsayılan takvim sırası (satırdaki ▲▼ ile yönetilir). Gizli alana
        // (maaş/telefon görünmüyorsa) göre sıralanmaz — sıra, gizli değeri sızdırmasın.
        $gizli = ['maas' => ! ($gor['maas'] ?? true), 'telefon' => ! ($gor['telefon'] ?? true)];
        if ($sirala && isset(self::SIRALAMA[$sirala]) && empty($gizli[$sirala])) {
            $sorgu->orderBy(self::SIRALAMA[$sirala], $yon === 'desc' ? 'desc' : 'asc');
        }
        $sorgu->orderBy('sp.takvim_sirasi', 'asc')->orderBy('sp.id', 'asc');

        // Tablo araması: satırda görünen içerik — ad, unvan, telefon (yalnız görünüyorsa).
        if ($like = ListeIstegi::like($baslik)) {
            $sorgu->where(function ($w) use ($like, $gizli) {
                $w->where('sp.personel_adi', 'like', $like)->orWhere('sp.unvan', 'like', $like);
                if (! $gizli['telefon']) {
                    $w->orWhere('sp.cep_telefon', 'like', $like);
                }
            });
        }

        $donustur = fn ($row) => $this->okuma->donustur($row, $gor);

        if ($tumu) {
            $satirlar = $sorgu->get()->map($donustur)->all();
            $sayfa = [
                'data'         => $satirlar,
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => count($satirlar),
                'total'        => count($satirlar),
            ];
        } else {
            $p = $sorgu->paginate($perPage);
            $sayfa = [
                'data'         => array_map($donustur, $p->items()),
                'current_page' => $p->currentPage(),
                'last_page'    => $p->lastPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
            ];
        }

        return $sayfa + ['ozet' => $this->ozet($salonId)];
    }

    /** Salonun arsivsiz personel sayilari (istatistik kartlari). */
    public function ozet(int $salonId): array
    {
        $r = DB::table('salon_personelleri')
            ->where('salon_id', $salonId)
            ->where(fn ($q) => $this->arsivsiz($q, 'arsivli'))
            ->selectRaw('COUNT(*) toplam, SUM(CASE WHEN aktif = 1 THEN 1 ELSE 0 END) aktif, '
                .'SUM(CASE WHEN takvimde_gorunsun = 1 THEN 1 ELSE 0 END) takvimde')
            ->first();

        return [
            'toplam'   => (int) ($r->toplam ?? 0),
            'aktif'    => (int) ($r->aktif ?? 0),
            'takvimde' => (int) ($r->takvimde ?? 0),
        ];
    }

    /** Arsivli = NULL veya false olmayan (personelArsivle bayragi). Tek tanim. */
    private function arsivsiz($q, string $kolon): void
    {
        $q->whereNull($kolon)->orWhere($kolon, false);
    }
}
