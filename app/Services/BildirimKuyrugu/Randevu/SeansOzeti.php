<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use Illuminate\Support\Facades\DB;

/**
 * Seans kullanim mesajinin hesabi ve metni; eski SeansBildirimService::paketBloku, hizmetBloku, mesajOlustur. Sayilar salon
 * panelindeki "Seans Takibi" ile ayni kuraldir:
 *   toplam = seans_sayisi (yoksa satir sayisi), tamamlanan = geldi=1, gelmedi = geldi=0, kalan = toplam - tamamlanan - gelmedi,
 *   bu randevuda = bu randevunun geldi=1 satirlari.
 */
class SeansOzeti
{
    /** @return array{tur:string, ad:string, seansSayisi:int, kullanilan:array, kullanilmayan:array}|null */
    public function paketBloku(int $adisyonPaketId, int $randevuId): ?array
    {
        $ap = DB::table('adisyon_paketler')->where('id', $adisyonPaketId)->first();
        if (! $ap) {
            return null;
        }

        $paketAdi = DB::table('paketler')->where('id', $ap->paket_id)->value('paket_adi') ?: 'Paketiniz';
        $seansSayisi = (int) ($ap->seans_sayisi ?? 0);

        // Panelle ayni kaynak: paket_hizmetler.
        $hizmetler = DB::table('paket_hizmetler')
            ->join('hizmetler', 'paket_hizmetler.hizmet_id', '=', 'hizmetler.id')
            ->where('paket_hizmetler.paket_id', $ap->paket_id)
            ->select('hizmetler.id as hid', 'hizmetler.hizmet_adi as ad')
            ->get();

        // Paket tanimindan hizmet gelmezse seans satirlarindaki hizmetlerden turetilir.
        if ($hizmetler->isEmpty()) {
            $hizmetler = DB::table('adisyon_paket_seanslar')
                ->join('hizmetler', 'adisyon_paket_seanslar.hizmet_id', '=', 'hizmetler.id')
                ->where('adisyon_paket_seanslar.adisyon_paket_id', $adisyonPaketId)
                ->select('hizmetler.id as hid', 'hizmetler.hizmet_adi as ad')
                ->distinct()->get();
        }

        $kullanilan = [];
        $kullanilmayan = [];
        foreach ($hizmetler as $h) {
            $seanslar = fn () => DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $adisyonPaketId)->where('hizmet_id', $h->hid);
            $satir = $this->satir($h->ad, $seansSayisi, $seanslar, $randevuId);
            if ($satir['bugun'] > 0) {
                $kullanilan[] = $satir;
            } else {
                $kullanilmayan[] = $satir;
            }
        }

        if (empty($kullanilan) && empty($kullanilmayan)) {
            return null;
        }

        return ['tur' => 'paket', 'ad' => $paketAdi, 'seansSayisi' => $seansSayisi, 'kullanilan' => $kullanilan, 'kullanilmayan' => $kullanilmayan];
    }

    /** Paket disi tekil hizmet seansi icin blok. @return array{tur:string, ad:string, seansSayisi:int, kullanilan:array, kullanilmayan:array}|null */
    public function hizmetBloku(int $adisyonHizmetId, int $randevuId): ?array
    {
        $ah = DB::table('adisyon_hizmetler')->where('id', $adisyonHizmetId)->first();
        if (! $ah) {
            return null;
        }

        $hizmetAdi = DB::table('hizmetler')->where('id', $ah->hizmet_id)->value('hizmet_adi') ?: 'Hizmetiniz';
        $seanslar = fn () => DB::table('adisyon_paket_seanslar')->where('adisyon_hizmet_id', $adisyonHizmetId);
        $satir = $this->satir($hizmetAdi, (int) ($ah->seans_sayisi ?? 0), $seanslar, $randevuId);

        if ($satir['bugun'] < 1 && $satir['tamamlanan'] < 1) {
            return null;
        }
        $satir['bugun'] = max(1, $satir['bugun']);

        return ['tur' => 'hizmet', 'ad' => $hizmetAdi, 'seansSayisi' => $satir['toplam'], 'kullanilan' => [$satir], 'kullanilmayan' => []];
    }

    /** @param  callable  $seanslar  satirlari suzen yeni sorgu uretir */
    private function satir(?string $ad, int $seansSayisi, callable $seanslar, int $randevuId): array
    {
        $tamamlanan = (int) $seanslar()->where('geldi', 1)->count();
        $gelmedi = (int) $seanslar()->where('geldi', 0)->count();
        $bekleyen = (int) $seanslar()->whereNull('geldi')->count();

        $toplam = $seansSayisi > 0 ? $seansSayisi : ($tamamlanan + $gelmedi + $bekleyen);

        return [
            'ad' => $ad, 'toplam' => $toplam, 'tamamlanan' => $tamamlanan,
            'kalan' => max(0, $toplam - $tamamlanan - $gelmedi),
            'bugun' => (int) $seanslar()->where('randevu_id', $randevuId)->where('geldi', 1)->count(),
        ];
    }

    /** Musteriye giden detayli metin. */
    public function mesaj(string $musteriIsmi, array $bloklar): string
    {
        $s = "✨ **SEANS TAKİP BİLGİLENDİRMESİ** ✨\n\n";
        $s .= "Merhaba **Sayın {$musteriIsmi}**, 🌸\n\n";
        $s .= "Bugünkü randevunuz başarıyla tamamlanmıştır. Aşağıda paketinizin güncel seans durumunu inceleyebilirsiniz.\n";

        foreach ($bloklar as $blok) {
            $etiket = $blok['tur'] === 'hizmet' ? 'Hizmetiniz' : 'Paketiniz';
            $seansEk = $blok['seansSayisi'] > 0 ? " (**{$blok['seansSayisi']} Seans**)" : '';
            $s .= "\n📦 **{$etiket}:** {$blok['ad']}{$seansEk}\n";

            if (! empty($blok['kullanilan'])) {
                $baslik = count($blok['kullanilan']) > 1 ? 'Bugün Kullanılan İşlemler' : 'Bugün Kullanılan İşlem';
                $s .= "\n✅ **{$baslik}**\n";
                foreach ($blok['kullanilan'] as $k) {
                    $s .= "**{$k['ad']}**\n";
                    $s .= "• Bu randevuda kullanılan: **{$k['bugun']} Seans**\n";
                    $s .= "• Tamamlanan: **{$k['tamamlanan']} / {$k['toplam']} Seans**\n";
                    $s .= "• Kalan: **{$k['kalan']} Seans**\n";
                }
            }

            if (! empty($blok['kullanilmayan'])) {
                $s .= "\n⏳ **Bugün Kullanılmayan İşlemler**\n";
                foreach ($blok['kullanilmayan'] as $k) {
                    $s .= "• **{$k['ad']}:** Kalan **{$k['kalan']} Seans**\n";
                }
            }
        }

        $s .= "\n📌 Seans bilgileriniz sistemimizde güncellenmiştir. Bir sonraki randevunuzda kalan haklarınızı dilediğiniz gibi kullanabilirsiniz.\n\n";
        $s .= '💚 Bizi tercih ettiğiniz için teşekkür eder, sağlıklı ve güzel günlerde yeniden görüşmeyi dileriz.';

        return $s;
    }
}
