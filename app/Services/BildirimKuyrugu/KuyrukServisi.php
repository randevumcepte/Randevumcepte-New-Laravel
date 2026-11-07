<?php

namespace App\Services\BildirimKuyrugu;

use App\Domain\Bildirim\BildirimKuyrukDurumu as Durum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * v2_bildirim_kuyrugu tuketicisi, eski V2BildirimIsle dongusunun karsiligi. Yalniz kendi durumlarini isler (BildirimKuyrukDurumu):
 * eski komut ayni anda calissa da satirlar karismaz. Talep ATOMIK (6→7 kosullu UPDATE). Hata: 3 denemeye kadar yeniden bekler,
 * sonra kalici hata. 5 dk'dan uzun islenende kalan satirlar geri alinir. 0, 3, 4, 5 durumlarina dokunulmaz.
 */
class KuyrukServisi
{
    public const MAKS_DENEME = 3;

    private const TABLO = 'v2_bildirim_kuyrugu';

    private const TUR_BASINA_KAYIT = 20;

    public function __construct(private BildirimYonlendirici $yonlendirici) {}

    public function tabloVarMi(): bool
    {
        return Schema::hasTable(self::TABLO);
    }

    /** Olmus surecten kalan 'isleniyor' satirlarini geri alir. */
    public function yaridaKalanlariGeriAl(): void
    {
        DB::table(self::TABLO)
            ->where('durum', Durum::ISLENIYOR)
            ->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-5 minutes')))
            ->update(['durum' => Durum::BEKLIYOR, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** Bir tur: en fazla 20 bekleyen kaydi talep et ve isle. @return int islenen kayit */
    public function birTur(): int
    {
        $idler = DB::table(self::TABLO)->where('durum', Durum::BEKLIYOR)->orderBy('id')->limit(self::TUR_BASINA_KAYIT)->pluck('id');
        $islenen = 0;

        foreach ($idler as $id) {
            $alindi = DB::table(self::TABLO)->where('id', $id)->where('durum', Durum::BEKLIYOR)
                ->update(['durum' => Durum::ISLENIYOR, 'updated_at' => date('Y-m-d H:i:s')]);
            if (! $alindi) {
                continue;
            }

            $kayit = DB::table(self::TABLO)->where('id', $id)->first();
            try {
                $this->yonlendirici->yonlendir($kayit);
                DB::table(self::TABLO)->where('id', $id)->update([
                    'durum' => Durum::GONDERILDI, 'islenme_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                $deneme = (int) $kayit->deneme + 1;
                DB::table(self::TABLO)->where('id', $id)->update([
                    'durum'      => $deneme >= self::MAKS_DENEME ? Durum::HATA : Durum::BEKLIYOR,
                    'deneme'     => $deneme,
                    'hata'       => mb_substr($e->getMessage(), 0, 2000),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Log::warning('[v2:bildirim-isle] hata', ['id' => $id, 'tur' => $kayit->tur, 'deneme' => $deneme, 'hata' => $e->getMessage()]);
            }
            $islenen++;
        }

        return $islenen;
    }
}
