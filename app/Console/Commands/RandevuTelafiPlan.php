<?php

namespace App\Console\Commands;

use App\Services\Randevu\TelafiRandevuServisi;
use Illuminate\Console\Command;

/**
 * DRY-RUN — telafi randevusu PLANINI gosterir (HICBIR SEY YAZMAZ): yeni randevu
 * alanlari + prim devri (satis/paket devri, odenmis prim geri devri tutarlari).
 * Eski sistemin ayni kalemde yapacagi devirle kiyaslamak icin.
 *
 * Kullanim:
 *   php artisan randevu:telafi-plan {rh_id} 2026-11-05 14:00 --personel=34
 */
class RandevuTelafiPlan extends Command
{
    protected $signature = 'randevu:telafi-plan {rh : orijinal randevu_hizmetler id}
        {tarih : Y-m-d} {saat : H:i}
        {--personel= : yeni personel (salon_personelleri.id); bos = ayni personel}
        {--oda=} {--cihaz=} {--sure= : dakika}';

    protected $description = 'v2 telafi randevusu DRY-RUN planini (prim devri dahil) goster';

    public function handle(TelafiRandevuServisi $servis): int
    {
        $sonuc = $servis->olustur((int) $this->argument('rh'), [
            'tarih'       => $this->argument('tarih'),
            'saat'        => $this->argument('saat'),
            'personel_id' => $this->option('personel'),
            'oda_id'      => $this->option('oda'),
            'cihaz_id'    => $this->option('cihaz'),
            'sure_dk'     => $this->option('sure'),
        ], null, false);

        $this->line(json_encode($sonuc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
