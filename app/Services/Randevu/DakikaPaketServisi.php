<?php

namespace App\Services\Randevu;

use Illuminate\Support\Facades\DB;

/**
 * Dakika paketi (solaryum vb. sure satislari) randevu dusum/iade — eski
 * App\Services\MusteriDakikaPaketService::randevuGeldiUygula/randevuGelmediIade
 * ile AYNI tablolar ve hareket turleri ('randevu_kullanim' / 'iade').
 *
 * v2 DUZELTMESI (NET hesap): eski kod "bu kaleme hic kullanim yazilmis mi?"
 * diye bakiyordu; geldi → gelmedi(iade) → tekrar geldi zincirinde ikinci geldi
 * HIC dusmuyordu. Burada kalem basina NET = kullanim − iade sayilir:
 *   geldi : NET == 0 olan kalemden dus
 *   iade  : NET  > 0 olan kalemin son kullanim(lar)ini iade et
 *
 * Bu sinif CAGIRAN transaction'i icinde calisir (kendi transaction'i yok).
 */
class DakikaPaketServisi
{
    /** Geldi'de dusulecek kalemler (plan — yazmaz). */
    public function dusumPlani(int $randevuId): array
    {
        $plan = [];
        $rhler = DB::table('randevu_hizmetler')
            ->where('randevu_id', $randevuId)
            ->whereNotNull('musteri_dakika_paketi_id')
            ->get(['id', 'musteri_dakika_paketi_id', 'paket_dakika', 'sure_dk']);

        foreach ($rhler as $rh) {
            if ($this->net((int) $rh->id) > 0) {
                continue; // zaten dusulmus
            }
            $dakika = (int) ($rh->paket_dakika ?? $rh->sure_dk ?? 0);
            if ($dakika <= 0) {
                continue;
            }
            $plan[] = ['rh_id' => (int) $rh->id, 'paket_id' => (int) $rh->musteri_dakika_paketi_id, 'dakika' => $dakika];
        }

        return $plan;
    }

    /** Gelmedi'de iade edilecek kullanim hareketleri (plan — yazmaz). */
    public function iadePlani(int $randevuId): array
    {
        $plan = [];
        $kullanimlar = DB::table('musteri_dakika_paket_hareketleri')
            ->where('randevu_id', $randevuId)
            ->where('tur', 'randevu_kullanim')
            ->orderByDesc('id')
            ->get(['id', 'musteri_dakika_paketi_id', 'randevu_hizmet_id', 'dakika']);

        foreach ($kullanimlar->groupBy('randevu_hizmet_id') as $rhId => $grup) {
            $net = $this->net((int) $rhId);
            foreach ($grup->take(max(0, $net)) as $h) {
                $plan[] = ['hareket_id' => (int) $h->id, 'rh_id' => (int) $rhId, 'paket_id' => (int) $h->musteri_dakika_paketi_id, 'dakika' => abs((int) $h->dakika)];
            }
        }

        return $plan;
    }

    /**
     * Dusum planini uygula. Paket aktif degil / bakiye yetersizse o kalem
     * ATLANIR (eski davranis: logla ve devam et) ve 'atlanan'da raporlanir.
     */
    public function dusumUygula(int $randevuId, array $plan, ?int $personelId): array
    {
        $uygulanan = [];
        $atlanan = [];
        foreach ($plan as $p) {
            $paket = DB::table('musteri_dakika_paketleri')->where('id', $p['paket_id'])->lockForUpdate()->first();
            if (! $paket || $paket->durum !== 'aktif' || (int) $paket->kalan_dakika < $p['dakika']) {
                $atlanan[] = $p + ['sebep' => ! $paket ? 'paket yok' : ($paket->durum !== 'aktif' ? 'paket aktif degil' : 'yetersiz bakiye')];
                continue;
            }
            $kalan = (int) $paket->kalan_dakika - $p['dakika'];
            DB::table('musteri_dakika_paketleri')->where('id', $p['paket_id'])->update([
                'kalan_dakika' => $kalan,
                'durum'        => $kalan === 0 ? 'bitti' : $paket->durum,
                'updated_at'   => now(),
            ]);
            $this->hareketYaz($p['paket_id'], $randevuId, $p['rh_id'], $p['dakika'], 'randevu_kullanim', null, $personelId);
            $uygulanan[] = $p;
        }

        return ['uygulanan' => $uygulanan, 'atlanan' => $atlanan];
    }

    public function iadeUygula(int $randevuId, array $plan, ?int $personelId): array
    {
        foreach ($plan as $p) {
            $paket = DB::table('musteri_dakika_paketleri')->where('id', $p['paket_id'])->lockForUpdate()->first();
            if (! $paket) {
                continue;
            }
            $kalan = (int) $paket->kalan_dakika + $p['dakika'];
            DB::table('musteri_dakika_paketleri')->where('id', $p['paket_id'])->update([
                'kalan_dakika' => $kalan,
                'durum'        => ($kalan > 0 && $paket->durum === 'bitti') ? 'aktif' : $paket->durum,
                'updated_at'   => now(),
            ]);
            $this->hareketYaz($p['paket_id'], $randevuId, $p['rh_id'], -$p['dakika'], 'iade', 'Randevu geldi isareti geri alindi', $personelId);
        }

        return $plan;
    }

    /** Kalem basina NET dusum sayisi = kullanim − iade. */
    private function net(int $rhId): int
    {
        $sayim = DB::table('musteri_dakika_paket_hareketleri')
            ->where('randevu_hizmet_id', $rhId)
            ->whereIn('tur', ['randevu_kullanim', 'iade'])
            ->selectRaw("SUM(tur = 'randevu_kullanim') k, SUM(tur = 'iade') i")
            ->first();

        return (int) ($sayim->k ?? 0) - (int) ($sayim->i ?? 0);
    }

    private function hareketYaz(int $paketId, int $randevuId, int $rhId, int $dakika, string $tur, ?string $aciklama, ?int $personelId): void
    {
        DB::table('musteri_dakika_paket_hareketleri')->insert([
            'musteri_dakika_paketi_id' => $paketId,
            'randevu_id'               => $randevuId,
            'randevu_hizmet_id'        => $rhId,
            'dakika'                   => $dakika,
            'tur'                      => $tur,
            'tarih'                    => now(),
            'aciklama'                 => $aciklama,
            'olusturan_user_id'        => null,
            'olusturan_personel_id'    => $personelId,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }
}
