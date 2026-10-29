<?php

namespace App\Services\Tahsilat;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Domain\Tahsilat\TahsilatDagitim;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\Adisyonlar;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use App\Models\Masraf;
use App\Models\MasrafKategorisi;
use App\Models\SenetVadeleri;
use App\Models\Tahsilat;
use App\Models\TahsilatHizmetler;
use App\Models\TahsilatPaketler;
use App\Models\TahsilatUrunler;
use App\Models\TaksitVadeleri;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahsilat YAZMA servisi — DRY-RUN (planla) ve GERÇEK (yaz, flag arkasında).
 *
 * İKİ MOD:
 *   - BASİT: adisyona ödeme → taksitsiz/senetsiz açık kalemlere kalan-bazlı havuz.
 *   - VADE : taksit_vade_id[]/senet_vade_id[] ödemesi → o planın kalemlerine havuz,
 *            vade 'odendi' işaretlenir; kısmi ödemede taksit vadesi bölünür.
 *
 * Dağıtım her yerde TahsilatDagitim (WEB-kanonik havuz+cap, kuruş-kesin). planla()
 * iki modu da TEK biçim plan üretir; yaz() planı transaction + idempotency ile
 * aynen persist eder (parent + kalem + komisyon + vade güncellemeleri).
 */
class TahsilatYazServisi
{
    private function vadeVar(array $g): bool
    {
        return ! empty($g['taksit_vade_id']) || ! empty($g['senet_vade_id']);
    }

    public function planla(array $g): array
    {
        return $this->vadeVar($g) ? $this->vadePlanla($g) : $this->basitPlanla($g);
    }

    // ------------------------------------------------------------------
    // BASİT MOD — adisyona ödeme
    // ------------------------------------------------------------------
    private function basitPlanla(array $g): array
    {
        $salonId = (int) ($g['salon_id'] ?? 0);
        $adisyonId = $this->adisyonIdCoz($g);

        $hizmetId = array_map('intval', (array) ($g['kalem_hizmet_id'] ?? []));
        $urunId   = array_map('intval', (array) ($g['kalem_urun_id'] ?? []));
        $paketId  = array_map('intval', (array) ($g['kalem_paket_id'] ?? []));

        $hizmetler = $this->kalemGetir(AdisyonHizmetler::class, $adisyonId, $hizmetId);
        $urunler   = $this->kalemGetir(AdisyonUrunler::class, $adisyonId, $urunId);
        $paketler  = $this->kalemGetir(AdisyonPaketler::class, $adisyonId, $paketId);

        $oncekiH = $this->oncekiTahsilat('tahsilat_hizmetler', 'adisyon_hizmet_id', $hizmetler->pluck('id')->all());
        $oncekiU = $this->oncekiTahsilat('tahsilat_urunler', 'adisyon_urun_id', $urunler->pluck('id')->all());
        $oncekiP = $this->oncekiTahsilat('tahsilat_paketler', 'adisyon_paket_id', $paketler->pluck('id')->all());

        $kalanlar = [];
        $ref = [];
        foreach ($hizmetler as $h) {
            $kalanlar[] = $this->kalanKurus($h, $oncekiH[$h->id] ?? 0);
            $ref[] = ['tip' => 'hizmet', 'fk' => 'adisyon_hizmet_id', 'id' => (int) $h->id];
        }
        foreach ($paketler as $p) {
            $kalanlar[] = $this->kalanKurus($p, $oncekiP[$p->id] ?? 0);
            $ref[] = ['tip' => 'paket', 'fk' => 'adisyon_paket_id', 'id' => (int) $p->id];
        }
        foreach ($urunler as $u) {
            $kalanlar[] = $this->kalanKurus($u, $oncekiU[$u->id] ?? 0);
            $ref[] = ['tip' => 'urun', 'fk' => 'adisyon_urun_id', 'id' => (int) $u->id];
        }

        [$tutarKurus, $komisyonKurus, $adisyonPaymentKurus] = $this->tutarlar($g);
        $paylar = TahsilatDagitim::taksitHavuz($kalanlar, $adisyonPaymentKurus);

        $kalemTahsilatlari = [];
        $dagitilan = 0;
        foreach ($paylar as $i => $payKurus) {
            $k = $ref[$i];
            $dagitilan += $payKurus;
            $kalemTahsilatlari[] = [
                'tip' => $k['tip'], $k['fk'] => $k['id'],
                'tutar' => AdisyonHesap::kurusToTl($payKurus), 'tutar_kurus' => $payKurus,
            ];
        }

        $toplamKalan = array_sum($kalanlar);
        $dagitilmayan = max(0, $adisyonPaymentKurus - $dagitilan);
        $uyarilar = [];
        if ($dagitilmayan > 0) {
            $uyarilar[] = 'Ödeme toplam kalan borçtan fazla; fazla '.AdisyonHesap::kurusToTl($dagitilmayan).' TL dağıtılmadı.';
        }
        if (empty($ref)) {
            $uyarilar[] = 'Adisyonda dağıtılacak (taksitsiz/senetsiz) kalem yok.';
        }

        return $this->planZarfi('basit', $adisyonId, $salonId, $g, $komisyonKurus, $kalemTahsilatlari, [], [
            'adisyon_payment_kurus' => $adisyonPaymentKurus,
            'toplam_kalan_kurus'    => $toplamKalan,
            'dagitilan_kurus'       => $dagitilan,
            'dagitilmayan_kurus'    => $dagitilmayan,
            'invariant_ok'          => $dagitilan === min($adisyonPaymentKurus, $toplamKalan),
        ], $uyarilar);
    }

    // ------------------------------------------------------------------
    // VADE MOD — taksit/senet vade ödemesi
    // ------------------------------------------------------------------
    private function vadePlanla(array $g): array
    {
        $salonId = (int) ($g['salon_id'] ?? 0);
        $adisyonId = (int) ($g['adisyon_id'] ?? 0);
        [$tutarKurus, $komisyonKurus, $adisyonPaymentKurus] = $this->tutarlar($g);
        $odemeYontemi = isset($g['odeme_yontemi_id']) ? (int) $g['odeme_yontemi_id'] : null;

        $taksitIds = array_map('intval', (array) ($g['taksit_vade_id'] ?? []));
        $senetIds  = array_map('intval', (array) ($g['senet_vade_id'] ?? []));

        $kalemTahsilatlari = [];
        $vadeGuncellemeleri = [];
        $uyarilar = [];
        $dagitilan = 0;
        $remaining = $adisyonPaymentKurus;

        // Taksit → sonra senet (eski sıra).
        foreach ([['taksit', $taksitIds, TaksitVadeleri::class, 'taksitli_tahsilat_id'],
            ['senet', $senetIds, SenetVadeleri::class, 'senet_id']] as [$tip, $ids, $model, $fk]) {
            foreach ($ids as $vid) {
                if ($remaining <= 0) {
                    break;
                }
                $vade = $model::find($vid);
                if (! $vade) {
                    $uyarilar[] = "$tip vade $vid bulunamadı";
                    continue;
                }
                $vadeTutarKurus = AdisyonHesap::tlToKurus($vade->tutar);
                $kismiKurus = min($remaining, $vadeTutarKurus);

                [$kalanlar, $ref] = $this->vadeKalemKalanlari($fk, $vade->{$fk});
                $paylar = TahsilatDagitim::taksitHavuz($kalanlar, $kismiKurus);
                foreach ($paylar as $i => $payKurus) {
                    $k = $ref[$i];
                    $dagitilan += $payKurus;
                    $kalemTahsilatlari[] = [
                        'tip' => $k['tip'], $k['fk'] => $k['id'],
                        'tutar' => AdisyonHesap::kurusToTl($payKurus), 'tutar_kurus' => $payKurus,
                    ];
                }

                // Kısmi ödemede taksit/senet vadesi bölünür (kalan kısım yeni vadeye).
                $split = $vadeTutarKurus > $kismiKurus ? AdisyonHesap::kurusToTl($vadeTutarKurus - $kismiKurus) : null;

                $vadeGuncellemeleri[] = [
                    'tip'              => $tip,
                    'vade_id'          => (int) $vade->id,
                    'odeme_yontemi_id' => $odemeYontemi,
                    'odendi'           => true,
                    'paid_tutar'       => AdisyonHesap::kurusToTl($kismiKurus),
                    'split_yeni_tutar' => $split,
                ];
                $remaining -= $kismiKurus;
            }
        }

        $dagitilmayan = max(0, $adisyonPaymentKurus - $dagitilan);
        if ($dagitilmayan > 0) {
            $uyarilar[] = 'Ödeme vade/kalan toplamından fazla; fazla '.AdisyonHesap::kurusToTl($dagitilmayan).' TL dağıtılmadı.';
        }
        if (empty($vadeGuncellemeleri)) {
            $uyarilar[] = 'Geçerli taksit/senet vadesi bulunamadı.';
        }

        return $this->planZarfi('vade', $adisyonId, $salonId, $g, $komisyonKurus, $kalemTahsilatlari, $vadeGuncellemeleri, [
            'adisyon_payment_kurus' => $adisyonPaymentKurus,
            'dagitilan_kurus'       => $dagitilan,
            'dagitilmayan_kurus'    => $dagitilmayan,
        ], $uyarilar);
    }

    // ------------------------------------------------------------------
    // GERÇEK YAZMA
    // ------------------------------------------------------------------
    public function yaz(array $g, array $meta): array
    {
        $anahtar = trim((string) ($g['idempotency_key'] ?? ''));
        if ($anahtar === '') {
            throw new CakismaException('idempotency_key zorunlu (çift-kayıt koruması)');
        }

        $mevcut = DB::table('api_idempotency_anahtarlari')->where('anahtar', $anahtar)->where('uc', 'tahsilat')->first();
        if ($mevcut && $mevcut->yanit_json) {
            $v = json_decode($mevcut->yanit_json, true);
            $v['replay'] = true;

            return $v;
        }

        try {
            return DB::transaction(function () use ($g, $meta, $anahtar) {
                $adisyonId = (int) $g['adisyon_id'];
                DB::table('adisyonlar')->where('id', $adisyonId)->lockForUpdate()->first();

                DB::table('api_idempotency_anahtarlari')->insert([
                    'anahtar' => $anahtar, 'uc' => 'tahsilat',
                    'yetkili_id' => $meta['yetkili_id'] ?? null, 'salon_id' => $meta['salon_id'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $plan = $this->planla($g); // kilit altında taze
                $adisyon = Adisyonlar::find($adisyonId);
                $salonId = $meta['salon_id'] ?? ($adisyon->salon_id ?? null);
                $olusturanId = $meta['olusturan_id'] ?? null;

                // 1) Parent tahsilat
                $t = new Tahsilat();
                $t->adisyon_id = $adisyonId;
                $t->tutar = $plan['parent_tahsilat']['tutar'];
                if (Schema::hasColumn('tahsilatlar', 'komisyon_tutari')) {
                    $t->komisyon_tutari = $plan['parent_tahsilat']['komisyon_tutari'];
                }
                $t->user_id = $adisyon->user_id ?? null;
                $t->odeme_tarihi = $plan['parent_tahsilat']['odeme_tarihi'];
                $t->olusturan_id = $olusturanId;
                $t->salon_id = $salonId;
                $t->yapilan_odeme = $plan['parent_tahsilat']['tutar'];
                $t->odeme_yontemi_id = $plan['parent_tahsilat']['odeme_yontemi_id'];
                $t->notlar = $g['notlar'] ?? null;
                $t->save();

                // 2) Kalem tahsilatları
                foreach ($plan['kalem_tahsilatlari'] as $kt) {
                    if ($kt['tip'] === 'hizmet') {
                        $o = new TahsilatHizmetler();
                        $o->adisyon_hizmet_id = $kt['adisyon_hizmet_id'];
                    } elseif ($kt['tip'] === 'urun') {
                        $o = new TahsilatUrunler();
                        $o->adisyon_urun_id = $kt['adisyon_urun_id'];
                    } else {
                        $o = new TahsilatPaketler();
                        $o->adisyon_paket_id = $kt['adisyon_paket_id'];
                    }
                    $o->tahsilat_id = $t->id;
                    $o->tutar = $kt['tutar'];
                    $o->save();
                }

                // 3) Komisyon
                if ($plan['komisyon']) {
                    $this->komisyonYaz($plan, $adisyonId, $adisyon, $salonId, $olusturanId, $t->id);
                }

                // 4) Vade güncellemeleri (taksit/senet odendi + bölme)
                foreach ($plan['vade_guncellemeleri'] as $vg) {
                    $model = $vg['tip'] === 'taksit' ? TaksitVadeleri::class : SenetVadeleri::class;
                    $vade = $model::find($vg['vade_id']);
                    if (! $vade) {
                        continue;
                    }
                    if ($vg['split_yeni_tutar'] !== null) {
                        $yeni = $vade->replicate();
                        $yeni->tutar = $vg['split_yeni_tutar'];
                        $yeni->odendi = 0;
                        $yeni->save();
                        $vade->tutar = $vg['paid_tutar'];
                    }
                    $vade->odendi = $vg['odendi'] ? 1 : 0;
                    if ($vg['odeme_yontemi_id'] !== null) {
                        $vade->odeme_yontemi_id = $vg['odeme_yontemi_id'];
                    }
                    $vade->save();
                }

                $yanit = [
                    'tahsilat_id' => (int) $t->id,
                    'adisyon_id'  => $adisyonId,
                    'mod'         => $plan['mod'],
                    'yazilan'     => [
                        'parent'   => 1,
                        'kalem'    => count($plan['kalem_tahsilatlari']),
                        'komisyon' => (bool) $plan['komisyon'],
                        'vade'     => count($plan['vade_guncellemeleri']),
                    ],
                    'replay' => false,
                ];

                DB::table('api_idempotency_anahtarlari')->where('anahtar', $anahtar)->where('uc', 'tahsilat')
                    ->update(['yanit_kodu' => 200, 'yanit_json' => json_encode($yanit), 'updated_at' => now()]);

                return $yanit;
            });
        } catch (QueryException $e) {
            if ($this->benzersizIhlali($e)) {
                $row = DB::table('api_idempotency_anahtarlari')->where('anahtar', $anahtar)->where('uc', 'tahsilat')->first();
                if ($row && $row->yanit_json) {
                    $v = json_decode($row->yanit_json, true);
                    $v['replay'] = true;

                    return $v;
                }
                throw new CakismaException('Bu istek zaten işleniyor, lütfen tekrar deneyin');
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Yardımcılar
    // ------------------------------------------------------------------

    public function salonIdGetir(int $adisyonId): ?int
    {
        $v = Adisyonlar::where('id', $adisyonId)->value('salon_id');

        return $v !== null ? (int) $v : null;
    }

    private function adisyonIdCoz(array $g): int
    {
        $adisyonId = (int) ($g['adisyon_id'] ?? 0);
        if ($adisyonId) {
            return $adisyonId;
        }
        $hizmetId = array_map('intval', (array) ($g['kalem_hizmet_id'] ?? []));
        $urunId   = array_map('intval', (array) ($g['kalem_urun_id'] ?? []));
        $paketId  = array_map('intval', (array) ($g['kalem_paket_id'] ?? []));
        $adisyonId = (int) (
            (! empty($hizmetId) ? AdisyonHizmetler::whereIn('id', $hizmetId)->value('adisyon_id') : null)
            ?? (! empty($urunId) ? AdisyonUrunler::whereIn('id', $urunId)->value('adisyon_id') : null)
            ?? (! empty($paketId) ? AdisyonPaketler::whereIn('id', $paketId)->value('adisyon_id') : null)
            ?? 0
        );
        if (! $adisyonId) {
            throw new BulunamadiException('Adisyon belirlenemedi (adisyon_id veya kalem id gerekli)');
        }

        return $adisyonId;
    }

    /** @return array{0:int,1:int,2:int} [tutarKurus, komisyonKurus, adisyonPaymentKurus] */
    private function tutarlar(array $g): array
    {
        $tutarKurus = AdisyonHesap::tlToKurus($g['tutar'] ?? 0);
        $komisyonKurus = AdisyonHesap::tlToKurus($g['komisyon'] ?? 0);

        return [$tutarKurus, $komisyonKurus, max(0, $tutarKurus - $komisyonKurus)];
    }

    private function kalanKurus($kalem, float $onceki): int
    {
        return max(0, AdisyonHesap::tlToKurus($kalem->fiyat ?? 0)
            - AdisyonHesap::tlToKurus($kalem->indirim_tutari ?? 0)
            - AdisyonHesap::tlToKurus($onceki));
    }

    /** Bir taksit/senet planının kalemleri → [kalanlar(kurus)[], ref[]] */
    private function vadeKalemKalanlari(string $fk, $planId): array
    {
        $kalanlar = [];
        $ref = [];
        $map = [
            [AdisyonHizmetler::class, 'hizmet', 'adisyon_hizmet_id', 'tahsilat_hizmetler'],
            [AdisyonUrunler::class, 'urun', 'adisyon_urun_id', 'tahsilat_urunler'],
            [AdisyonPaketler::class, 'paket', 'adisyon_paket_id', 'tahsilat_paketler'],
        ];
        foreach ($map as [$model, $tip, $kalemFk, $tahTablo]) {
            $items = $model::where($fk, $planId)->get();
            if ($items->isEmpty()) {
                continue;
            }
            $onceki = $this->oncekiTahsilat($tahTablo, $kalemFk, $items->pluck('id')->all());
            foreach ($items as $it) {
                $kalanlar[] = $this->kalanKurus($it, $onceki[$it->id] ?? 0);
                $ref[] = ['tip' => $tip, 'fk' => $kalemFk, 'id' => (int) $it->id];
            }
        }

        return [$kalanlar, $ref];
    }

    private function kalemGetir(string $model, int $adisyonId, array $ids)
    {
        $q = $model::where('adisyon_id', $adisyonId)
            ->whereNull('senet_id')
            ->whereNull('taksitli_tahsilat_id');
        if (! empty($ids)) {
            $q->whereIn('id', $ids);
        }

        return $q->get();
    }

    /** @return array<int,float> kalem_id => önceki tahsilat (TL) */
    private function oncekiTahsilat(string $tablo, string $fk, array $kalemIds): array
    {
        if (empty($kalemIds)) {
            return [];
        }

        return DB::table($tablo)
            ->whereIn($fk, $kalemIds)
            ->selectRaw("$fk as kid, COALESCE(SUM(tutar),0) as t")
            ->groupBy($fk)
            ->pluck('t', 'kid')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function komisyonYaz(array $plan, int $adisyonId, $adisyon, $salonId, $olusturanId, int $parentId): void
    {
        $kom = $plan['komisyon']['gelir_tahsilat']['tutar'];

        $kt = new Tahsilat();
        $kt->adisyon_id = $adisyonId;
        $kt->tutar = $kom;
        $kt->user_id = $adisyon->user_id ?? null;
        $kt->odeme_tarihi = $plan['parent_tahsilat']['odeme_tarihi'];
        $kt->olusturan_id = $olusturanId;
        $kt->salon_id = $salonId;
        $kt->yapilan_odeme = $kom;
        $kt->odeme_yontemi_id = $plan['parent_tahsilat']['odeme_yontemi_id'];
        $kt->para_girisi = 1;
        $kt->notlar = 'Komisyon Geliri (tahsilat #'.$parentId.')';
        $kt->save();

        $bankaKat = MasrafKategorisi::firstOrCreate(['kategori' => 'Banka Ödemeleri']);
        $m = new Masraf();
        $m->salon_id = $salonId;
        if (Schema::hasColumn('masraflar', 'adisyon_id')) {
            $m->adisyon_id = $adisyonId;
        }
        $m->harcayan_id = $olusturanId;
        $m->masraf_kategori_id = $bankaKat->id;
        $m->tarih = $plan['parent_tahsilat']['odeme_tarihi'];
        $m->tutar = $kom;
        $m->odeme_yontemi_id = $plan['parent_tahsilat']['odeme_yontemi_id'];
        $m->aciklama = 'Komisyon Tutarı';
        $m->save();
    }

    private function planZarfi(string $mod, int $adisyonId, int $salonId, array $g, int $komisyonKurus, array $kalemTahsilatlari, array $vadeGuncellemeleri, array $ozet, array $uyarilar): array
    {
        return [
            'dry_run'    => true,
            'mod'        => $mod,
            'adisyon_id' => $adisyonId,
            'salon_id'   => $salonId,
            'parent_tahsilat' => [
                'adisyon_id'       => $adisyonId,
                'tutar'            => AdisyonHesap::kurusToTl(max(0, AdisyonHesap::tlToKurus($g['tutar'] ?? 0) - $komisyonKurus)),
                'komisyon_tutari'  => AdisyonHesap::kurusToTl($komisyonKurus),
                'para_girisi'      => 0,
                'odeme_yontemi_id' => isset($g['odeme_yontemi_id']) ? (int) $g['odeme_yontemi_id'] : null,
                'odeme_tarihi'     => $g['odeme_tarihi'] ?? null,
            ],
            'komisyon' => $komisyonKurus > 0 ? [
                'gelir_tahsilat' => ['tutar' => AdisyonHesap::kurusToTl($komisyonKurus), 'para_girisi' => 1],
                'masraf'         => ['kategori' => 'Banka Ödemeleri', 'tutar' => AdisyonHesap::kurusToTl($komisyonKurus)],
            ] : null,
            'kalem_tahsilatlari'  => $kalemTahsilatlari,
            'vade_guncellemeleri' => $vadeGuncellemeleri,
            'ozet'                => $ozet,
            'uyarilar'            => $uyarilar,
        ];
    }

    private function benzersizIhlali(QueryException $e): bool
    {
        return (($e->errorInfo[1] ?? null) === 1062) || ((string) $e->getCode() === '23000');
    }
}
