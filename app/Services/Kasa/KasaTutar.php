<?php

namespace App\Services\Kasa;

use App\Domain\Satis\KalemTuru;
use Illuminate\Support\Facades\DB;

/**
 * Kasada gosterilen tahsilat tutari — salonlar.faturasiz_gizle ayari TEK yerde.
 *
 * Ayar aciksa yalnizca FATURA KESILMIS adisyon kalemlerine dusen kisim sayilir (kalem
 * tahsilat tablolari); manuel para girisi gibi kalemsiz tahsilatlar disarida kalir.
 * Eski kodda bu blok 5 yerde kopyaydi (web kasa + gun sonu, mobil kasaraporu +
 * tahsilatraporu + gunSonuRaporu) ve salonun TUM gecmisini PHP'ye cekiyordu; burada
 * satir basina SQL ifadesi (yalniz istenen tahsilatlar icin calisir).
 */
class KasaTutar
{
    /** Salonda faturasiz gizleme acik mi? */
    public function faturaliMi(int $salonId): bool
    {
        return (bool) DB::table('salonlar')->where('id', $salonId)->value('faturasiz_gizle');
    }

    /** Tahsilatin gosterilen tutari (SQL) — $t: tahsilatlar alias'i. */
    public static function tutarSql(bool $faturali, string $t = 't'): string
    {
        return $faturali ? self::faturaliSql($t) : "COALESCE({$t}.tutar,0)";
    }

    /** Tahsilatin fatura kesilmis adisyon kalemlerine dusen kismi (SQL). */
    public static function faturaliSql(string $t = 't'): string
    {
        $parcalar = array_map(function (KalemTuru $tur) use ($t) {
            return '(SELECT COALESCE(SUM(fx.tutar),0) FROM '.$tur->tahsilatTablosu().' fx'
                .' JOIN '.$tur->kalemTablosu().' fk ON fk.id = fx.'.$tur->tahsilatFk()
                .' JOIN adisyonlar fa ON fa.id = fk.adisyon_id'
                ." WHERE fx.tahsilat_id = {$t}.id AND fa.fatura_kesildi = 1)";
        }, KalemTuru::cases());

        return '('.implode(' + ', $parcalar).')';
    }

    /**
     * "Kaleme bagli degil" (bagsiz) tahsilat (SQL bool): para girisi degil, var olan
     * bir kaleme bagli kalem tahsilati yok ve var olan bir adisyona bagli degil.
     */
    public static function bagsizSql(string $t = 't'): string
    {
        $kalemYok = array_map(fn (KalemTuru $tur) => 'NOT EXISTS (SELECT 1 FROM '.$tur->tahsilatTablosu().' bx'
            .' JOIN '.$tur->kalemTablosu().' bk ON bk.id = bx.'.$tur->tahsilatFk()
            ." WHERE bx.tahsilat_id = {$t}.id)", KalemTuru::cases());

        return "(COALESCE({$t}.para_girisi,0) = 0 AND "
            .implode(' AND ', $kalemYok)
            ." AND NOT EXISTS (SELECT 1 FROM adisyonlar ba WHERE ba.id = {$t}.adisyon_id))";
    }
}
