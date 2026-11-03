<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * aranacak_musteriler üzerindeki ORTAK sorgu koşulları — kuyruk, segment, toplu taşıma, CSV ve
 * kart sayaçları aynı kuralı kullanır (eski kodda her uçta ayrı yazılmıştı).
 */
final class AramaKaydiSorgusu
{
    /**
     * Çalışma ekranında GÖRÜNEN kayıt: listeden YALNIZ bekleyen arama randevusu (durum 3,
     * tamamlanmamış) çıkar — o "Arama Randevuları" sekmesinde yaşar. Ön görüşme / satış /
     * randevu oluşturuldu / iptal LİSTEDE KALIR.
     */
    public static function kuyrukta(Builder $q, string $t = ''): Builder
    {
        $c = $t !== '' ? $t.'.' : '';

        return $q->where(fn ($w) => $w->whereNull($c.'durum')->orWhere($c.'durum', '<>', AramaSonucu::TEKRAR_ARANACAK->value)
            ->orWhereNotNull($c.'ar_tamamlandi_at'));
    }

    /** SQL ifadesi: kuyrukta mı (kart sayacı için SUM CASE). */
    public static function kuyrukSql(string $t = ''): string
    {
        $c = $t !== '' ? $t.'.' : '';

        return "({$c}durum IS NULL OR {$c}durum <> 3 OR {$c}ar_tamamlandi_at IS NOT NULL)";
    }

    /**
     * Sonuç kodlarına göre süz ('bekleyen' = NULL, diğerleri durum kodu). Boş liste → hiçbir kayıt.
     *
     * @param array<int,string|int|null> $kodlar
     */
    public static function sonucKodlari(Builder $q, array $kodlar, string $t = ''): Builder
    {
        $c = $t !== '' ? $t.'.' : '';
        $bekleyen = in_array('bekleyen', $kodlar, true) || in_array(null, $kodlar, true);
        $sayilar = array_values(array_unique(array_map('intval', array_filter($kodlar, fn ($k) => $k !== 'bekleyen' && $k !== null && is_numeric($k)))));
        if (! $bekleyen && ! $sayilar) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($w) use ($bekleyen, $sayilar, $c) {
            if ($bekleyen) {
                $w->orWhereNull($c.'durum');
            }
            if ($sayilar) {
                $w->orWhereIn($c.'durum', $sayilar);
            }
        });
    }

    /** Liste başına {toplam, arandi, kuyruk} — TEK sorgu (N+1 yok). @return array<int, object> */
    public static function listeSayaclari(array $listeIdleri): array
    {
        if (! $listeIdleri) {
            return [];
        }

        return DB::table('aranacak_musteriler')->whereIn('arama_id', $listeIdleri)
            ->select('arama_id', DB::raw('COUNT(*) as toplam'),
                DB::raw('SUM(CASE WHEN durum IS NOT NULL THEN 1 ELSE 0 END) as arandi'),
                DB::raw('SUM(CASE WHEN '.self::kuyrukSql().' THEN 1 ELSE 0 END) as kuyruk'))
            ->groupBy('arama_id')->get()->keyBy('arama_id')->all();
    }

    /** Ses kaydı sayısı + en yeni kayıt tarihi (görüşme notlarından; santral API'ye yük bindirmez). */
    public static function sesOzetleri(array $aranacakIdleri): array
    {
        if (! $aranacakIdleri) {
            return [];
        }

        return DB::table('gorusme_notlari')->whereIn('aranacak_musteri_id', $aranacakIdleri)
            ->whereNotNull('ses_kaydi')->where('ses_kaydi', '!=', '')
            ->select('aranacak_musteri_id', DB::raw('COUNT(*) as adet'), DB::raw('MAX(created_at) as son'))
            ->groupBy('aranacak_musteri_id')->get()->keyBy('aranacak_musteri_id')->all();
    }
}
