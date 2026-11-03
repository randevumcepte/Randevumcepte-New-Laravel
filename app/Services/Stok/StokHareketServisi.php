<?php

namespace App\Services\Stok;

use App\Domain\Stok\HareketTipi;
use App\Domain\Stok\Miktar;
use App\Models\Depo;
use App\Models\StokHareketi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stogu degistiren TEK yol (eski StokController::hareketKaydet karsiligi).
 *
 * Her degisim: stok_hareketleri'ne bir defter satiri + urun_depo_stoklari onbellegi
 * + urunler.stok_adedi (geriye uyumlu toplam). Hepsi ayni transaction'da.
 *
 * Eski koddan GUVENLI farklar:
 *   - Onbellek ATOMIK artirilir (UPDATE stok = stok + ?); eski oku-topla-yaz
 *     es zamanli iki satista birini kaybediyordu.
 *   - depo_id hic bos kalmaz (verilmezse urunun / salonun varsayilan deposu) —
 *     eskiden depo'suz hareket onbellege yansimiyordu.
 *   - Isaret kurali HareketTipi'nde (cikis tipi daima negatif).
 */
class StokHareketServisi
{
    public const KULLANICI_TIPI = 'isletme_yonetim';

    /** Salonun varsayilan deposu; yoksa 'Ana Depo' olusturulur (eskiyle ayni). */
    public function varsayilanDepo(int $salonId): Depo
    {
        return Depo::where('salon_id', $salonId)->where('varsayilan', 1)->where('aktif', 1)->first()
            ?? Depo::create(['salon_id' => $salonId, 'depo_adi' => 'Ana Depo', 'varsayilan' => 1, 'aktif' => 1]);
    }

    /** Verilen depo; yoksa urunun varsayilan deposu; o da yoksa salonun varsayilan deposu. */
    public function depoCoz(int $salonId, ?int $depoId, ?int $urunDepoId = null): int
    {
        return $depoId ?: ($urunDepoId ?: (int) $this->varsayilanDepo($salonId)->id);
    }

    /** Bir islemdeki tum hareketleri birbirine baglayan kimlik (alis fisi, transfer, sayim…). */
    public function yeniBatch(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Urun×depo anlik stok; $kilitle=true ise satir transaction sonuna dek kilitlenir
     * (sayim/transfer farki hesaplanirken araya baska hareket girmesin).
     */
    public function mevcut(int $urunId, int $depoId, bool $kilitle = false): float
    {
        $q = DB::table('urun_depo_stoklari')->where('urun_id', $urunId)->where('depo_id', $depoId);
        if ($kilitle) {
            $q->lockForUpdate();
        }

        return (float) $q->value('stok');
    }

    /**
     * Tek hareket yaz.
     *
     * @param  array{salon_id:int, urun_id:int, depo_id:int, miktar:float|int|string, tip:HareketTipi,
     *               referans_tip?:?string, referans_id?:?int, batch_uuid?:?string,
     *               birim_alis_fiyati?:mixed, birim_satis_fiyati?:mixed, aciklama?:?string,
     *               kullanici_id?:?int, tarih?:mixed}  $h
     */
    public function kaydet(array $h): StokHareketi
    {
        /** @var HareketTipi $tip */
        $tip = $h['tip'];
        $miktar = Miktar::yuvarla($tip->isaretle((float) $h['miktar']));

        return DB::transaction(function () use ($h, $tip, $miktar) {
            $hareket = StokHareketi::create([
                'salon_id'           => $h['salon_id'],
                'urun_id'            => $h['urun_id'],
                'depo_id'            => $h['depo_id'],
                'miktar'             => $miktar,
                'hareket_tipi'       => $tip->value,
                'referans_tip'       => $h['referans_tip'] ?? null,
                'referans_id'        => $h['referans_id'] ?? null,
                'batch_uuid'         => $h['batch_uuid'] ?? null,
                'birim_alis_fiyati'  => self::fiyat($h['birim_alis_fiyati'] ?? null),
                'birim_satis_fiyati' => self::fiyat($h['birim_satis_fiyati'] ?? null),
                'aciklama'           => $h['aciklama'] ?? null,
                'kullanici_id'       => $h['kullanici_id'] ?? null,
                'kullanici_tipi'     => self::KULLANICI_TIPI,
                'tarih'              => $h['tarih'] ?? now(),
            ]);

            $simdi = now();
            DB::table('urun_depo_stoklari')->insertOrIgnore([
                'salon_id' => $h['salon_id'], 'urun_id' => $h['urun_id'], 'depo_id' => $h['depo_id'],
                'stok' => 0, 'created_at' => $simdi, 'updated_at' => $simdi,
            ]);
            DB::table('urun_depo_stoklari')->where('urun_id', $h['urun_id'])->where('depo_id', $h['depo_id'])
                ->increment('stok', $miktar, ['updated_at' => $simdi]);

            $this->toplamSenkron((int) $h['urun_id']);

            return $hareket;
        });
    }

    /** urunler.stok_adedi = tum depolarin toplami (eski ekranlar/raporlar bu kolonu okur). */
    private function toplamSenkron(int $urunId): void
    {
        $toplam = (float) DB::table('urun_depo_stoklari')->where('urun_id', $urunId)->sum('stok');
        DB::table('urunler')->where('id', $urunId)->update(['stok_adedi' => Miktar::yuvarla($toplam)]);
    }

    private static function fiyat(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : round((float) $v, 2);
    }
}
