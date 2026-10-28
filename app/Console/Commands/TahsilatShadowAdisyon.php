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

        // (A) PARITE: yeni servis (mobil-parite kalem) == eski mobil formul (kalem)?
        $pariteSapan = [];
        // (B) MUTABAKAT: parent 'tahsilatlar' vs kalem-split (ileriki tek-ledger icin)
        $mutabakatSapan = [];
        $pariteTam = 0;

        foreach ($ids as $id) {
            $yeni = $servis->getir($id)['hesap'];     // net + odenen(kalem) — kurus
            $eski = $this->eskiFormul((int) $id);     // net + odenen(kalem) — kurus

            $ok = $yeni['net_kurus'] === $eski['net_kurus']
               && $yeni['odenen_kurus'] === $eski['odenen_kurus'];
            if ($ok) {
                $pariteTam++;
            } else {
                $pariteSapan[] = ['id' => $id, 'yeni' => $yeni, 'eski' => $eski];
            }

            $parentKurus = $this->parentOdenenKurus((int) $id);
            if ($parentKurus !== $eski['odenen_kurus']) {
                $mutabakatSapan[] = ['id' => $id, 'parent' => $parentKurus, 'kalem' => $eski['odenen_kurus']];
            }
        }

        $this->line('');
        $this->info("=== PARITE (yeni v2 vs eski mobil) ===");
        $this->info("Tam eslesen: $pariteTam / {$ids->count()}");
        $this->warn('Parite sapan: '.count($pariteSapan));
        foreach (array_slice($pariteSapan, 0, 20) as $s) {
            $this->line("  #{$s['id']}  net yeni/eski={$s['yeni']['net_kurus']}/{$s['eski']['net_kurus']}  odenen yeni/eski={$s['yeni']['odenen_kurus']}/{$s['eski']['odenen_kurus']}");
        }
        if (empty($pariteSapan)) {
            $this->info('PARITE TAM — v2 okuma, eski mobil ile birebir (Flutter rakamlari degismez).');
        }

        $this->line('');
        $this->info("=== MUTABAKAT (parent tahsilatlar vs kalem-split) — ileriki tek-ledger icin bilgi ===");
        $this->warn('Celisen adisyon: '.count($mutabakatSapan).' / '.$ids->count());
        foreach (array_slice($mutabakatSapan, 0, 20) as $s) {
            $this->line("  #{$s['id']}  parent={$s['parent']}  kalem={$s['kalem']}  (fark=".($s['parent'] - $s['kalem']).')');
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

    /** Parent 'tahsilatlar' (komisyon geliri haric) toplami — KURUS. */
    private function parentOdenenKurus(int $id): int
    {
        $tl = (float) DB::table('tahsilatlar')
            ->where('adisyon_id', $id)
            ->where(function ($q) {
                $q->where('para_girisi', 0)->orWhereNull('para_girisi');
            })
            ->selectRaw('COALESCE(SUM(tutar),0) s')->value('s');

        return (int) round($tl * 100);
    }
}
