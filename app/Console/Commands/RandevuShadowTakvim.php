<?php

namespace App\Console\Commands;

use App\Models\Personeller;
use App\Services\Randevu\RandevuOkumaServisi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SHADOW — Yeni RandevuOkumaServisi (v2 takvim) vs ESKI randevular() filtre/
 * kapsam mantiginin BAGIMSIZ ham-SQL replikasi.
 *
 * SALT OKUMA — hicbir sey yazmaz. Gercek randevular uzerinde dogrular:
 *   (A) SATIR KUMESI: v2'nin dondurdugu randevu_hizmetler.id kumesi == eski
 *       WHERE (durum<2 + tarih araligi + salon + rol-5 personel filtresi) kumesi.
 *   (B) ZAMAN/KAYNAK: her satirin start/end'i ve resourceId'si (takvim turune
 *       gore) eski mantikla BIREBIR.
 *
 * Amac: yazma/kesme (sonraki faz) oncesi, v2 takvim okumasinin eski ile AYNI
 * randevulari, ayni zaman penceresi ve ayni kaynak bagiyla urettigini kanitlamak.
 *
 * Kozmetik (renk sirasi, PAKET/KARMA etiketi, merge, notes) bu fazda
 * KARSILASTIRILMAZ (cutover fazina ertelendi).
 *
 * Kullanim:
 *   php artisan randevu:shadow-takvim 20 --tarih1=2026-10-01 --tarih2=2026-10-31
 *   php artisan randevu:shadow-takvim 20 --personel=1234 --takvim-turu=1
 */
class RandevuShadowTakvim extends Command
{
    protected $signature = 'randevu:shadow-takvim {salon : salon_id}
        {--tarih1= : baslangic (Y-m-d), varsayilan bugun}
        {--tarih2= : bitis (Y-m-d), varsayilan +30 gun}
        {--personel= : salon_personelleri.id (rol-5 kapsam testi icin)}
        {--takvim-turu= : 0 kategori / 1 personel / 2 cihaz / 3 oda}';

    protected $description = 'Yeni v2 takvim okumasi vs eski randevular() filtre/zaman/kaynak paritesi (salt okuma)';

    public function handle(RandevuOkumaServisi $servis): int
    {
        $salon  = (int) $this->argument('salon');
        $tarih1 = $this->option('tarih1') ?: date('Y-m-d');
        $tarih2 = $this->option('tarih2') ?: date('Y-m-d', strtotime('+30 days'));
        $personelId = $this->option('personel') ? (int) $this->option('personel') : null;

        $takvimTuru = $this->option('takvim-turu');
        $takvimTuru = ($takvimTuru !== null && $takvimTuru !== '')
            ? (int) $takvimTuru
            : (int) (DB::table('salonlar')->where('id', $salon)->value('randevu_takvim_turu') ?? 1);

        // Rol-5 kapsamini eski ile ayni coz (shadow'un kendi, bagimsiz hesabi).
        $personelRolu = $this->personelRolu($salon, $personelId);

        $this->info("Salon $salon  tarih=[$tarih1 .. $tarih2]  takvim_turu=$takvimTuru  personel=".($personelId ?? '-')."  rol=$personelRolu");
        $this->line('');

        // (YENI) v2 pre-merge satirlar.
        $yeni = collect($servis->hamSatirlar($salon, $tarih1, $tarih2, $personelId, $takvimTuru, $personelRolu))
            ->keyBy('rh_id');

        // (ESKI) bagimsiz ham-SQL replikasi: ayni WHERE/scoping.
        $eski = $this->eskiSatirlar($salon, $tarih1, $tarih2, $personelId, $takvimTuru, $personelRolu)
            ->keyBy('rh_id');

        $yeniIds = $yeni->keys()->map('intval')->sort()->values();
        $eskiIds = $eski->keys()->map('intval')->sort()->values();

        // (A) SATIR KUMESI
        $sadeceYeni = $yeniIds->diff($eskiIds)->values();
        $sadeceEski = $eskiIds->diff($yeniIds)->values();

        $this->info('=== (A) SATIR KUMESI ===');
        $this->line("v2: {$yeniIds->count()} satir   eski: {$eskiIds->count()} satir");
        if ($sadeceYeni->isEmpty() && $sadeceEski->isEmpty()) {
            $this->info('SET TAM — v2 ve eski ayni randevu_hizmetler kumesini donuyor.');
        } else {
            $this->warn('Sadece v2\'de: '.$sadeceYeni->implode(', '));
            $this->warn('Sadece eski\'de: '.$sadeceEski->implode(', '));
        }

        // (B) ZAMAN / KAYNAK
        $this->line('');
        $this->info('=== (B) ZAMAN / KAYNAK (kesisen satirlar) ===');
        $ortak = $yeniIds->intersect($eskiIds);
        $sapan = [];
        foreach ($ortak as $rhId) {
            $y = $yeni[$rhId];
            $e = $eski[$rhId];
            $farklar = [];
            if ($y['start'] !== $e['start']) {
                $farklar[] = "start y={$y['start']} e={$e['start']}";
            }
            if ($y['end'] !== $e['end']) {
                $farklar[] = "end y={$y['end']} e={$e['end']}";
            }
            if ((string) $y['resourceId'] !== (string) $e['resourceId']) {
                $farklar[] = "resourceId y={$y['resourceId']} e={$e['resourceId']}";
            }
            if ($farklar) {
                $sapan[] = "  rh#$rhId  ".implode('  ', $farklar);
            }
        }
        if (empty($sapan)) {
            $this->info("ZAMAN/KAYNAK TAM — {$ortak->count()} satirin start/end/resourceId'si birebir.");
        } else {
            $this->warn('Sapan satir: '.count($sapan));
            foreach (array_slice($sapan, 0, 30) as $s) {
                $this->line($s);
            }
        }

        $tam = $sadeceYeni->isEmpty() && $sadeceEski->isEmpty() && empty($sapan);
        $this->line('');
        $this->{$tam ? 'info' : 'warn'}($tam ? 'SONUC: PARITE TAM.' : 'SONUC: SAPMA VAR (yukari bak).');

        return self::SUCCESS;
    }

    /** Rol-5 kapsam cozumu — eski randevular() ile ayni (bagimsiz). */
    private function personelRolu(int $salon, ?int $personelId): int
    {
        if (! $personelId) {
            return 0;
        }
        $rol = (int) (Personeller::where('id', $personelId)->value('role_id') ?? 0);
        if ($rol !== 5) {
            return $rol;
        }
        $caller = Personeller::where('id', $personelId)->value('yetkili_id');
        if ($caller && app(\App\Services\Yetki\YetkiServisi::class)->yetkiliYetkiVar($caller, $salon, 'randevu.tum_personel_gor')) {
            return 0;
        }

        return 5;
    }

    /**
     * Eski randevular() WHERE/zaman/kaynak mantiginin bagimsiz ham-SQL replikasi.
     *
     * @return \Illuminate\Support\Collection<int,array{rh_id:int,start:string,end:string,resourceId:mixed}>
     */
    private function eskiSatirlar(int $salon, string $tarih1, string $tarih2, ?int $personelId, int $takvimTuru, int $personelRolu)
    {
        $q = DB::table('randevu_hizmetler as rh')
            ->join('randevular as r', 'rh.randevu_id', '=', 'r.id')
            ->where('r.salon_id', $salon)
            ->where('r.durum', '<', 2)
            ->where('r.tarih', '>=', $tarih1)
            ->where('r.tarih', '<=', $tarih2);

        if ($personelRolu === 5 && $personelId) {
            $q->where('rh.personel_id', $personelId);
        }

        $kaynakKolon = match ($takvimTuru) {
            1       => 'rh.personel_id',
            2       => 'rh.cihaz_id',
            3       => 'rh.oda_id',
            default => null, // kategori: hizmetler.hizmet_kategori_id
        };

        $rows = $q->get([
            'rh.id as rh_id', 'rh.saat', 'rh.saat_bitis', 'rh.hizmet_id',
            'rh.personel_id', 'rh.cihaz_id', 'rh.oda_id', 'r.tarih',
        ]);

        // Kategori modu icin hizmet->kategori haritasi (tek sorgu).
        $kategoriByHizmet = collect();
        if ($kaynakKolon === null) {
            $kategoriByHizmet = DB::table('hizmetler')
                ->whereIn('id', $rows->pluck('hizmet_id')->filter()->unique()->all())
                ->pluck('hizmet_kategori_id', 'id');
        }

        return $rows->map(function ($r) use ($takvimTuru, $personelRolu, $kaynakKolon, $kategoriByHizmet) {
            if ($personelRolu === 5) {
                $resourceId = $r->personel_id ?? 0;
            } elseif ($kaynakKolon === 'rh.personel_id') {
                $resourceId = $r->personel_id ?? 0;
            } elseif ($kaynakKolon === 'rh.cihaz_id') {
                $resourceId = $r->cihaz_id ?? 0;
            } elseif ($kaynakKolon === 'rh.oda_id') {
                $resourceId = $r->oda_id ?? 0;
            } else {
                $resourceId = $r->hizmet_id ? ($kategoriByHizmet[$r->hizmet_id] ?? null) : null;
            }

            return [
                'rh_id'      => (int) $r->rh_id,
                'start'      => Carbon::parse($r->tarih.' '.$r->saat)->toIso8601String(),
                'end'        => Carbon::parse($r->tarih.' '.$r->saat_bitis)->toIso8601String(),
                'resourceId' => $resourceId,
            ];
        });
    }
}
