<?php

namespace App\Services\Anket;

use App\Domain\Anket\AnketOdulu;
use App\Domain\Anket\AnketSorulari;
use App\Exceptions\BulunamadiException;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * Anket şablonu OKUMA + API biçimi. Web tablosu ve Flutter aynı alanları okur: Flutter editörü
 * `sorular_json` metnini çözdüğü için `sorular` dizisinin yanında o alan da döner (normalize edilmiş).
 */
class AnketSablonSorgusu
{
    /** ListeIstegi sıralama beyaz listesi: API alanı → SQL. */
    public const SIRALAMA = ['ad' => 'ad', 'aktif' => 'aktif', 'cevap_sayisi' => 'cevap_sayisi', 'id' => 'id'];

    private const CEVAP_SAYISI = '(SELECT COUNT(*) FROM anket_gonderimleri g WHERE g.sablon_id = anket_sablonlari.id AND g.cevaplandi = 1) as cevap_sayisi';

    public function salonId(int $id): ?int
    {
        $s = DB::table('anket_sablonlari')->where('id', $id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste
     * @return array{data:array, current_page:int, last_page:int, per_page:int, total:int}
     */
    public function listele(int $salonId, array $liste): array
    {
        $q = DB::table('anket_sablonlari')->where('salon_id', $salonId);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('ad', 'like', $like)->orWhere('aciklama', 'like', $like));
        }
        $toplam = (clone $q)->count();

        // Varsayılan: aktif → varsayılan → ad (eski mobil liste sırası).
        $alan = self::SIRALAMA[$liste['sirala'] ?? ''] ?? null;
        $alan
            ? $q->orderBy($alan, $liste['yon'] ?? 'asc')
            : $q->orderByDesc('aktif')->orderByDesc('varsayilan')->orderBy('ad');

        $satirlar = $q->select('anket_sablonlari.*')->selectRaw(self::CEVAP_SAYISI)
            ->forPage($liste['page'], $liste['per_page'])->get();

        return ['data' => $satirlar->map(fn ($s) => self::sunum($s))->values()->all()]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    public function getir(int $id): array
    {
        $s = DB::table('anket_sablonlari')->where('id', $id)->select('anket_sablonlari.*')->selectRaw(self::CEVAP_SAYISI)->first();
        if (! $s) {
            throw new BulunamadiException('Şablon bulunamadı');
        }

        return self::sunum($s);
    }

    private static function sunum(object $s): array
    {
        $sorular = AnketSorulari::coz($s->sorular_json);
        $odul = AnketOdulu::satirdan($s, AnketOdulu::ONEK_SABLON);
        $otomatik = (bool) $s->otomatik_gonder;
        $saat = (int) $s->gonder_saat_sonra;

        return [
            'id'                => (int) $s->id,
            'salon_id'          => (int) $s->salon_id,
            'ad'                => (string) $s->ad,
            'aciklama'          => $s->aciklama,
            'sorular'           => $sorular,
            'sorular_json'      => json_encode($sorular, JSON_UNESCAPED_UNICODE),
            'soru_sayisi'       => count($sorular),
            'cevap_sayisi'      => (int) ($s->cevap_sayisi ?? 0),
            'otomatik_gonder'   => $otomatik,
            'gonder_saat_sonra' => $saat,
            'gonderim_ozet'     => ! $otomatik ? 'Manuel' : ($saat === 0 ? 'Hizmet süresi sonunda' : "Hizmet bitiminden {$saat} sa sonra"),
            'aktif'             => (bool) $s->aktif,
            'varsayilan'        => (bool) $s->varsayilan,
            'odul_ozet'         => $odul->kisaEtiket(),
        ] + $odul->kolonlar(AnketOdulu::ONEK_SABLON);
    }
}
