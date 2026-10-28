<?php

namespace App\Services\Tahsilat;

use App\Domain\Tahsilat\AdisyonHesap;
use App\Domain\Tahsilat\TahsilatDagitim;
use App\Exceptions\BulunamadiException;
use App\Models\AdisyonHizmetler;
use App\Models\AdisyonPaketler;
use App\Models\AdisyonUrunler;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat YAZMA servisi — şimdilik yalnızca DRY-RUN (planla).
 *
 * planla(): bir tahsilat girdisinin üreteceği TÜM satırları (parent tahsilatlar +
 * kalem tahsilatları + komisyon gelir/masraf + kalan alacak) hesaplar ve DÖNER;
 * HİÇBİRİNİ YAZMAZ. Gerçek yazma (transaction + idempotency + dual-write) ayrı,
 * kullanıcı onayıyla açılacak.
 *
 * Dağıtım: TahsilatDagitim::taksitHavuz (WEB-kanonik, kalan-bazlı, kuruş-kesin).
 * Ödeme her kaleme KALAN borcu oranında dağıtılır; kimse kendi kalanını aşmaz;
 * ödeme toplam kalanı aşarsa fazlası dağıtılmaz (uyarı ile raporlanır).
 *
 * Kapsam notu: taksit/senet VADE ödemesi (taksit_vade_id/senet_vade_id) bu ilk
 * sürümde YOK — sonraki artımda eklenecek. Bu sürüm "adisyona ödeme" çekirdeği.
 */
class TahsilatYazServisi
{
    /**
     * @param array $g Girdi: salon_id, adisyon_id?, tutar, komisyon?, odeme_yontemi_id?,
     *                  odeme_tarihi?, kalem_hizmet_id[]?, kalem_urun_id[]?, kalem_paket_id[]?
     * @return array Yazılacak satırların PLANI (hiçbir şey yazılmaz)
     *
     * @throws BulunamadiException
     */
    public function planla(array $g): array
    {
        $salonId = (int) ($g['salon_id'] ?? 0);
        $adisyonId = (int) ($g['adisyon_id'] ?? 0);

        // Kalem id dizileri (verilmezse adisyonun TUM taksitsiz/senetsiz kalemleri).
        $hizmetId = array_map('intval', (array) ($g['kalem_hizmet_id'] ?? []));
        $urunId   = array_map('intval', (array) ($g['kalem_urun_id'] ?? []));
        $paketId  = array_map('intval', (array) ($g['kalem_paket_id'] ?? []));

        // adisyon_id gelmezse kalem id'lerinden türet (eski akış 17169-17177).
        if (! $adisyonId) {
            $adisyonId = (int) (
                (! empty($hizmetId) ? AdisyonHizmetler::whereIn('id', $hizmetId)->value('adisyon_id') : null)
                ?? (! empty($urunId) ? AdisyonUrunler::whereIn('id', $urunId)->value('adisyon_id') : null)
                ?? (! empty($paketId) ? AdisyonPaketler::whereIn('id', $paketId)->value('adisyon_id') : null)
                ?? 0
            );
        }
        if (! $adisyonId) {
            throw new BulunamadiException('Adisyon belirlenemedi (adisyon_id veya kalem id gerekli)');
        }

        // Kalemleri topla (verilen id'ler ya da adisyonun tamami); senet/taksit HARIC
        // (onlar vade akisinda islenir, burada degil).
        $hizmetler = $this->kalemGetir(AdisyonHizmetler::class, $adisyonId, $hizmetId);
        $urunler   = $this->kalemGetir(AdisyonUrunler::class, $adisyonId, $urunId);
        $paketler  = $this->kalemGetir(AdisyonPaketler::class, $adisyonId, $paketId);

        // Her kalem icin ONCEKI tahsilat (kalem-split) — batched.
        $oncekiH = $this->oncekiTahsilat('tahsilat_hizmetler', 'adisyon_hizmet_id', $hizmetler->pluck('id')->all());
        $oncekiU = $this->oncekiTahsilat('tahsilat_urunler', 'adisyon_urun_id', $urunler->pluck('id')->all());
        $oncekiP = $this->oncekiTahsilat('tahsilat_paketler', 'adisyon_paket_id', $paketler->pluck('id')->all());

        // Kalan borc (kurus) per kalem + meta.
        $kalemler = [];
        foreach ($hizmetler as $h) {
            $kalemler[] = $this->kalemSatiri('hizmet', 'adisyon_hizmet_id', $h, $oncekiH[$h->id] ?? 0);
        }
        foreach ($urunler as $u) {
            $kalemler[] = $this->kalemSatiri('urun', 'adisyon_urun_id', $u, $oncekiU[$u->id] ?? 0);
        }
        foreach ($paketler as $p) {
            $kalemler[] = $this->kalemSatiri('paket', 'adisyon_paket_id', $p, $oncekiP[$p->id] ?? 0);
        }

        $tutarKurus    = AdisyonHesap::tlToKurus($g['tutar'] ?? 0);
        $komisyonKurus = AdisyonHesap::tlToKurus($g['komisyon'] ?? 0);
        $adisyonPaymentKurus = max(0, $tutarKurus - $komisyonKurus); // komisyon haric fiili odeme

        // Dağıtım (kalan-bazlı havuz + cap).
        $kalanlar = array_map(fn ($k) => $k['kalan_kurus'], $kalemler);
        $paylar = TahsilatDagitim::taksitHavuz($kalanlar, $adisyonPaymentKurus);

        $kalemTahsilatlari = [];
        $dagitilanKurus = 0;
        foreach ($paylar as $i => $payKurus) {
            $k = $kalemler[$i];
            $dagitilanKurus += $payKurus;
            $kalemTahsilatlari[] = [
                'tip'        => $k['tip'],
                $k['fk']     => $k['id'],
                'tutar'      => AdisyonHesap::kurusToTl($payKurus),
                'tutar_kurus'=> $payKurus,
            ];
        }

        $toplamKalanKurus = array_sum($kalanlar);
        $dagitilmayanKurus = max(0, $adisyonPaymentKurus - $dagitilanKurus); // odeme > kalan ise fazla

        $uyarilar = [];
        if ($dagitilmayanKurus > 0) {
            $uyarilar[] = 'Ödeme toplam kalan borçtan fazla; fazla '
                .AdisyonHesap::kurusToTl($dagitilmayanKurus).' TL dağıtılmadı (fazla ödeme).';
        }
        if (empty($kalemler)) {
            $uyarilar[] = 'Adisyonda dağıtılacak (taksitsiz/senetsiz) kalem yok.';
        }

        return [
            'dry_run'   => true,
            'adisyon_id' => $adisyonId,
            'salon_id'   => $salonId,
            'parent_tahsilat' => [
                'adisyon_id'       => $adisyonId,
                'tutar'            => AdisyonHesap::kurusToTl($adisyonPaymentKurus),
                'komisyon_tutari'  => AdisyonHesap::kurusToTl($komisyonKurus),
                'para_girisi'      => 0,
                'odeme_yontemi_id' => isset($g['odeme_yontemi_id']) ? (int) $g['odeme_yontemi_id'] : null,
                'odeme_tarihi'     => $g['odeme_tarihi'] ?? null,
            ],
            'komisyon' => $komisyonKurus > 0 ? [
                'gelir_tahsilat' => ['tutar' => AdisyonHesap::kurusToTl($komisyonKurus), 'para_girisi' => 1],
                'masraf'         => ['kategori' => 'Banka Ödemeleri', 'tutar' => AdisyonHesap::kurusToTl($komisyonKurus)],
            ] : null,
            'kalem_tahsilatlari' => $kalemTahsilatlari,
            'ozet' => [
                'adisyon_payment_kurus' => $adisyonPaymentKurus,
                'toplam_kalan_kurus'    => $toplamKalanKurus,
                'dagitilan_kurus'       => $dagitilanKurus,
                'dagitilmayan_kurus'    => $dagitilmayanKurus,
                'invariant_ok'          => $dagitilanKurus === min($adisyonPaymentKurus, $toplamKalanKurus),
            ],
            'uyarilar' => $uyarilar,
        ];
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

    private function kalemSatiri(string $tip, string $fk, $k, float $onceki): array
    {
        $kalan = AdisyonHesap::tlToKurus($k->fiyat ?? 0)
            - AdisyonHesap::tlToKurus($k->indirim_tutari ?? 0)
            - AdisyonHesap::tlToKurus($onceki);

        return [
            'tip'         => $tip,
            'fk'          => $fk,
            'id'          => (int) $k->id,
            'kalan_kurus' => max(0, $kalan),
        ];
    }

    /** @return array<int,float> kalem_id => onceki tahsilat toplami (TL) */
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
}
