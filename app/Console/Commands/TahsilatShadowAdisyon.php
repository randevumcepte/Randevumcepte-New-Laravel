<?php

namespace App\Console\Commands;

use App\Models\Adisyonlar;
use App\Services\Tahsilat\AdisyonOkumaServisi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SHADOW — Yeni AdisyonHesap (servis, parent tahsilatlar) vs ESKI formul
 * (formatAdisyonFast kalem-split, ham SQL) parasal parite karsilastirmasi.
 *
 * SALT OKUMA — hicbir sey yazmaz. Gercek adisyonlar uzerinde kurusuna kadar
 * karsilastirir; sapanlari listeler. Amac: yazma (Faz 3) oncesi net/odenen/kalan
 * formulunun eski sistemle BIREBIR tuttugunu kanitlamak.
 *
 * Kullanim:  php artisan tahsilat:shadow-adisyon 20 --limit=500
 */
class TahsilatShadowAdisyon extends Command
{
    protected $signature = 'tahsilat:shadow-adisyon {salon : salon_id} {--limit=300}';

    protected $description = 'Yeni vs eski adisyon parasal formul paritesi (salt okuma)';

    public function handle(AdisyonOkumaServisi $servis): int
    {
        $salon = (int) $this->argument('salon');
        $limit = (int) $this->option('limit');

        $ids = Adisyonlar::where('salon_id', $salon)->orderByDesc('id')->limit($limit)->pluck('id');
        $this->info("Salon $salon — {$ids->count()} adisyon karsilastiriliyor...");

        $netSapan = [];
        $odenenSapan = [];      // parent (yeni) vs kalem-split (eski API)
        $tamEslesen = 0;

        foreach ($ids as $id) {
            $yeni = $servis->getir($id)['hesap'];     // net/odenen(parent)/kalan — kurus
            $eski = $this->eskiFormul((int) $id);     // net/odenen(kalem)/kalan — kurus

            $netOk    = $yeni['net_kurus'] === $eski['net_kurus'];
            $odenenOk = $yeni['odenen_kurus'] === $eski['odenen_kurus'];

            if (! $netOk) {
                $netSapan[] = ['id' => $id, 'yeni' => $yeni['net_kurus'], 'eski' => $eski['net_kurus']];
            }
            if (! $odenenOk) {
                $odenenSapan[] = ['id' => $id, 'yeni_parent' => $yeni['odenen_kurus'], 'eski_kalem' => $eski['odenen_kurus']];
            }
            if ($netOk && $odenenOk) {
                $tamEslesen++;
            }
        }

        $this->line('');
        $this->info("Tam eslesen (net+odenen): $tamEslesen / {$ids->count()}");
        $this->warn('NET sapan: '.count($netSapan));
        $this->warn('ODENEN sapan (parent vs kalem-split): '.count($odenenSapan));

        foreach (array_slice($netSapan, 0, 20) as $s) {
            $this->line("  NET  #{$s['id']}  yeni={$s['yeni']}  eski={$s['eski']}  (fark=".($s['yeni'] - $s['eski']).')');
        }
        foreach (array_slice($odenenSapan, 0, 20) as $s) {
            $this->line("  ODE  #{$s['id']}  parent={$s['yeni_parent']}  kalem={$s['eski_kalem']}  (fark=".($s['yeni_parent'] - $s['eski_kalem']).')');
        }

        if (empty($netSapan) && empty($odenenSapan)) {
            $this->info('SIFIR SAPMA — yeni hesap eski formulle birebir tutuyor.');
        }

        return self::SUCCESS;
    }

    /**
     * Eski API (formatAdisyonFast) formulunun ham-SQL replikasi — KURUS.
     *   net    = Σ(fiyat − indirim_tutari)  [adisyon_hizmetler + urunler + paketler]
     *   odenen = Σ tahsilat_*.tutar          [kalem tahsilat tablolari]
     */
    private function eskiFormul(int $id): array
    {
        $netTl =
            (float) DB::table('adisyon_hizmetler')->where('adisyon_id', $id)
                ->selectRaw('COALESCE(SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)),0) s')->value('s')
          + (float) DB::table('adisyon_urunler')->where('adisyon_id', $id)
                ->selectRaw('COALESCE(SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)),0) s')->value('s')
          + (float) DB::table('adisyon_paketler')->where('adisyon_id', $id)
                ->selectRaw('COALESCE(SUM(COALESCE(fiyat,0)-COALESCE(indirim_tutari,0)),0) s')->value('s');

        $odenenTl =
            (float) DB::table('tahsilat_hizmetler as t')->join('adisyon_hizmetler as a', 't.adisyon_hizmet_id', '=', 'a.id')
                ->where('a.adisyon_id', $id)->selectRaw('COALESCE(SUM(t.tutar),0) s')->value('s')
          + (float) DB::table('tahsilat_urunler as t')->join('adisyon_urunler as a', 't.adisyon_urun_id', '=', 'a.id')
                ->where('a.adisyon_id', $id)->selectRaw('COALESCE(SUM(t.tutar),0) s')->value('s')
          + (float) DB::table('tahsilat_paketler as t')->join('adisyon_paketler as a', 't.adisyon_paket_id', '=', 'a.id')
                ->where('a.adisyon_id', $id)->selectRaw('COALESCE(SUM(t.tutar),0) s')->value('s');

        $netKurus = (int) round($netTl * 100);
        $odenenKurus = (int) round($odenenTl * 100);

        return [
            'net_kurus'    => $netKurus,
            'odenen_kurus' => $odenenKurus,
            'kalan_kurus'  => $netKurus - $odenenKurus,
        ];
    }
}
