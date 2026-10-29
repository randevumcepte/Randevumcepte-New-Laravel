<?php

namespace App\Console\Commands;

use App\Services\Personel\PersonelListeServisi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SHADOW — v2 PersonelListeServisi vs ESKI ApiController@personelgetir paritesi.
 *
 * SALT OKUMA — hicbir sey yazmaz. Ayni paylasilan DB uzerinde, bir salonun
 * personel listesini iki yolla uretip alan-alan karsilastirir (id kumesi +
 * personel_adi/aktif/hesap_turu/maas). Amac: v2 okuma uclarinin eski API ile
 * birebir tuttugunu yazma (PERSONEL_V2_YAZMA_AKTIF) oncesi kanitlamak.
 *
 * Kullanim:  php artisan personel:shadow 20
 */
class PersonelShadow extends Command
{
    protected $signature = 'personel:shadow {salon : salon_id}';

    protected $description = 'Yeni v2 personel listesi vs eski personelgetir paritesi (salt okuma)';

    public function handle(PersonelListeServisi $liste): int
    {
        $salon = (int) $this->argument('salon');

        // v2: maskesiz (maasGor=true), tek sayfada tumu.
        $yeni = collect($liste->listele($salon, null, true, 10000)['data'])->keyBy('id');

        // Eski personelgetir ile BIREBIR sorgu (arsivli ele + takvim_sirasi sira).
        $eski = DB::table('salon_personelleri as sp')
            ->join('isletmeyetkilileri as iy', 'sp.yetkili_id', '=', 'iy.id')
            ->leftJoin('model_has_roles as mhr', function ($j) {
                $j->on('iy.id', '=', 'mhr.model_id')->on('mhr.salon_id', '=', 'sp.salon_id');
            })
            ->leftJoin('roles as r', 'mhr.role_id', '=', 'r.id')
            ->where('sp.salon_id', $salon)
            ->where(function ($q) {
                $q->whereNull('sp.arsivli')->orWhere('sp.arsivli', false);
            })
            ->groupBy('sp.id')
            ->select('sp.id', 'sp.personel_adi', 'sp.aktif', 'r.id as hesap_turu', 'sp.maas')
            ->get()
            ->keyBy('id');

        $this->info("Salon $salon — v2: {$yeni->count()} / eski: {$eski->count()} personel");

        $sapan = [];
        $tumIdler = $yeni->keys()->merge($eski->keys())->unique();
        foreach ($tumIdler as $id) {
            $y = $yeni->get($id);
            $e = $eski->get($id);
            if (! $y || ! $e) {
                $sapan[] = "#$id  ".($y ? 'eskide YOK' : 'v2de YOK');
                continue;
            }
            $farklar = [];
            if ($y['personel_adi'] !== $e->personel_adi) $farklar[] = "ad(v2={$y['personel_adi']}|eski={$e->personel_adi})";
            if ((int) $y['aktif'] !== (int) $e->aktif) $farklar[] = "aktif({$y['aktif']}|{$e->aktif})";
            if ((int) ($y['hesap_turu'] ?? 0) !== (int) ($e->hesap_turu ?? 0)) $farklar[] = "hesap_turu({$y['hesap_turu']}|{$e->hesap_turu})";
            if ((string) $y['maas'] !== (string) $e->maas) $farklar[] = "maas({$y['maas']}|{$e->maas})";
            if ($farklar) $sapan[] = "#$id  ".implode(' ', $farklar);
        }

        if (empty($sapan)) {
            $this->info('PARITE TAM — v2 personel listesi eski personelgetir ile birebir.');

            return self::SUCCESS;
        }

        $this->warn('Sapan: '.count($sapan));
        foreach (array_slice($sapan, 0, 50) as $s) {
            $this->line('  '.$s);
        }

        return self::FAILURE;
    }
}
