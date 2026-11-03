<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Liste (tablo) isteği — TÜM /api/v2 liste uçları için tek sözleşme (web DataTable
 * bileşeni lib/v2-tablo.js ve Flutter aynı parametreleri gönderir):
 *
 *   page      : 1..n
 *   per_page  : 1..100 (web 10/25/50/100 sunar; uç kendi varsayılanını verir)
 *   ara       : tablo içeriğinde serbest metin arama (eski adı 'baslik'/'search' da kabul)
 *   sirala    : yalnız ucun izin verdiği alanlar (beyaz liste); aksi halde varsayılan
 *   yon       : asc | desc
 *
 * Cevap tarafında meta() aynı sayfalama alanlarını üretir.
 */
final class ListeIstegi
{
    public const MAKS_SAYFA_BOYU = 100;

    private function __construct(
        public readonly int $sayfa,
        public readonly int $sayfaBoyu,
        public readonly ?string $ara,
        public readonly ?string $sirala,
        public readonly string $yon,
    ) {}

    /**
     * @param array<int,string> $siralanabilir  Ucun izin verdiği sıralama alanları
     */
    public static function istekten(
        Request $r,
        int $varsayilanBoy = 10,
        array $siralanabilir = [],
        ?string $varsayilanSirala = null,
        string $varsayilanYon = 'desc',
    ): self {
        $ara = trim((string) ($r->input('ara') ?? $r->input('baslik') ?? $r->input('search') ?? ''));
        $sirala = (string) $r->input('sirala', '');
        $yon = strtolower((string) $r->input('yon', ''));

        return new self(
            sayfa: max(1, (int) $r->input('page', 1)),
            sayfaBoyu: max(1, min(self::MAKS_SAYFA_BOYU, (int) ($r->input('per_page') ?: $varsayilanBoy))),
            ara: $ara === '' ? null : mb_substr($ara, 0, 100),
            sirala: in_array($sirala, $siralanabilir, true) ? $sirala : $varsayilanSirala,
            yon: in_array($yon, ['asc', 'desc'], true) ? $yon : $varsayilanYon,
        );
    }

    /** LIKE için kaçışlı '%ara%' deseni (kullanıcının % ve _ karakterleri joker sayılmaz). */
    public function likeDeseni(): ?string
    {
        return self::like($this->ara);
    }

    /** Servis katmanı için: ham arama metninden kaçışlı LIKE deseni (boşsa null). */
    public static function like(?string $ara): ?string
    {
        $ara = trim((string) $ara);

        return $ara === '' ? null : '%'.addcslashes($ara, '\\%_').'%';
    }

    /** Servislere geçilecek düz dizi (servisler Request'e bağımlı olmasın). */
    public function dizi(): array
    {
        return [
            'page'     => $this->sayfa,
            'per_page' => $this->sayfaBoyu,
            'ara'      => $this->ara,
            'sirala'   => $this->sirala,
            'yon'      => $this->yon,
        ];
    }

    /** Standart sayfalama meta alanları (her liste cevabında aynı). */
    public static function meta(int $sayfa, int $sayfaBoyu, int $toplam): array
    {
        return [
            'current_page' => $sayfa,
            'last_page'    => (int) max(1, (int) ceil($toplam / max(1, $sayfaBoyu))),
            'per_page'     => $sayfaBoyu,
            'total'        => $toplam,
        ];
    }
}
