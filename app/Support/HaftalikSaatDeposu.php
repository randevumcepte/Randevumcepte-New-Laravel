<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Haftalik calisma / mola saati tablolarinin ORTAK okuma-yazma noktasi — TEK KAYNAK.
 *
 * Ayni sema 3 sahipte tekrar ediyor (eski monolit): salon_*, personel_*, cihaz_*
 *   <sahip>_calisma_saatleri (<fk>, haftanin_gunu, calisiyor, baslangic_saati, bitis_saati)
 *   <sahip>_mola_saatleri    (<fk>, haftanin_gunu, mola_var,  baslangic_saati, bitis_saati)
 * Satir bicimi her yerde ayni: {id, haftanin_gunu, <bayrak>, baslangic_saati, bitis_saati}
 * (Flutter IsletmeCalismaSaatleri / personel saat modelleriyle uyumlu).
 *
 * Yazma eskiyle ayni: sahibin satirlari silinip yeniden yazilir (cagiran transaction acar).
 */
final class HaftalikSaatDeposu
{
    public const SAHIPLER = [
        'salon'    => 'salon_id',
        'personel' => 'personel_id',
        'cihaz'    => 'cihaz_id',
    ];

    public const CALISMA = 'calisma';
    public const MOLA = 'mola';

    private const BAYRAK = [self::CALISMA => 'calisiyor', self::MOLA => 'mola_var'];

    /** @return list<array{id:int,haftanin_gunu:int,calisiyor?:int,mola_var?:int,baslangic_saati:?string,bitis_saati:?string}> */
    public static function oku(string $sahip, int $sahipId, string $tur): array
    {
        [$tablo, $fk, $bayrak] = self::tanim($sahip, $tur);

        return DB::table($tablo)->where($fk, $sahipId)->orderBy('haftanin_gunu')
            ->get(['id', 'haftanin_gunu', $bayrak, 'baslangic_saati', 'bitis_saati'])
            ->map(fn ($s) => [
                'id'              => (int) $s->id,
                'haftanin_gunu'   => (int) $s->haftanin_gunu,
                $bayrak           => (int) $s->{$bayrak},
                'baslangic_saati' => $s->baslangic_saati,
                'bitis_saati'     => $s->bitis_saati,
            ])
            ->all();
    }

    /**
     * Sil-ve-yaz. $gunler satirlari {haftanin_gunu, <bayrak>, baslangic_saati, bitis_saati}.
     *
     * @param  iterable<array>  $gunler
     */
    public static function yaz(string $sahip, int $sahipId, string $tur, iterable $gunler): void
    {
        [$tablo, $fk, $bayrak] = self::tanim($sahip, $tur);
        $simdi = now();

        DB::table($tablo)->where($fk, $sahipId)->delete();
        $satirlar = [];
        foreach ($gunler as $gun) {
            $satirlar[] = [
                $fk               => $sahipId,
                'haftanin_gunu'   => (int) ($gun['haftanin_gunu'] ?? 0),
                $bayrak           => (int) ($gun[$bayrak] ?? 0),
                'baslangic_saati' => $gun['baslangic_saati'] ?? null,
                'bitis_saati'     => $gun['bitis_saati'] ?? null,
                'created_at'      => $simdi,
                'updated_at'      => $simdi,
            ];
        }
        if ($satirlar) {
            DB::table($tablo)->insert($satirlar);
        }
    }

    /** @return array{0:string,1:string,2:string} [tablo, fk, bayrak kolonu] */
    private static function tanim(string $sahip, string $tur): array
    {
        if (! isset(self::SAHIPLER[$sahip], self::BAYRAK[$tur])) {
            throw new \LogicException("Bilinmeyen haftalik saat tanimi: {$sahip}/{$tur}");
        }
        $tablo = $sahip.($tur === self::CALISMA ? '_calisma_saatleri' : '_mola_saatleri');

        return [$tablo, self::SAHIPLER[$sahip], self::BAYRAK[$tur]];
    }
}
