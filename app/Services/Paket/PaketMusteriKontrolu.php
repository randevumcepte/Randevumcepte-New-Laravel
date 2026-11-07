<?php

namespace App\Services\Paket;

use App\Domain\Seans\MobilSeansKurali;
use App\Models\Adisyonlar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Randevu formunda musterinin kalan paket ve hizmet seanslari. Kaynak: eski
 * ApiController::paketVarmiKontroluApi (mobil popup bu bicimi okur). Adisyonda otomatik randevu
 * acilmamis kalem varsa adisyonun TUM kalemleri degerlendirilir (eskiyle ayni).
 */
class PaketMusteriKontrolu
{
    public function __construct(private PaketKullanimi $kullanim) {}

    public function getir(int $salonId, int $musteriId, bool $onayVar): array
    {
        $musteri = DB::table('users')->where('id', $musteriId)->first(['id', 'name']);
        if (! $musteri) {
            return $this->bos('Kullanıcı bulunamadı.');
        }

        $hizmetAdisyonlari = $this->adisyonlar('hizmetler', $salonId, $musteriId)->with('hizmetler.hizmet')->get();
        $paketAdisyonlari = $this->adisyonlar('paketler', $salonId, $musteriId)->with('paketler.paket.hizmetler.hizmet')->get();
        if ($hizmetAdisyonlari->isEmpty() && $paketAdisyonlari->isEmpty()) {
            return $this->bos('');
        }

        $hizmetKalemleri = $hizmetAdisyonlari->flatMap->hizmetler;
        $paketKalemleri = $paketAdisyonlari->flatMap->paketler;
        $hizmetKayit = $this->kayitSayilari('adisyon_hizmet_id', $hizmetKalemleri->pluck('id')->all());
        $paketKayit = $this->kayitSayilari('adisyon_paket_id', $paketKalemleri->pluck('id')->all());
        $durumlar = $this->kullanim->durumlar($paketKalemleri->pluck('id')->map(fn ($id) => (int) $id)->all());

        $hizmetler = [];
        $detaylar = [];
        $sira = 0;

        foreach ($hizmetKalemleri as $k) {
            if (! $k->hizmet) {
                continue;
            }
            $kalan = MobilSeansKurali::hizmetKalani($k->seans_sayisi, $k->bekleyen_seans, $hizmetKayit[$k->id] ?? 0);
            if ($kalan <= 0) {
                continue;
            }
            $ad = $k->hizmet->hizmet_adi;
            $sure = $k->sure ?? 0;
            $hizmetler[] = ['id' => $k->hizmet_id, 'text' => $ad, 'seans' => $kalan, 'tur' => 'hizmet', 'sure' => $sure, 'paket_adi' => null, 'adisyon_hizmet_id' => $k->id];
            $detaylar[] = [
                'index' => $sira++, 'adi' => $ad, 'seans' => $kalan, 'tur' => 'Tek Hizmet', 'sure' => $sure,
                'hizmet_id' => $k->hizmet_id, 'type' => 'hizmet', 'adisyon_hizmet_id' => $k->id, 'paket_id' => null,
                'adisyon_paket_id' => null, 'toplamSeans' => $kalan,
                'icerik' => [['id' => $k->hizmet_id, 'text' => $ad, 'seans' => $kalan, 'sure' => $sure]],
            ];
        }

        foreach ($paketKalemleri as $k) {
            $durum = $durumlar[(int) $k->id] ?? ['sinirsiz' => false, 'sure' => '', 'suresi_doldu' => false];
            $hizmetSayisi = $k->paket ? count($k->paket->hizmetler) : 0;
            if ($hizmetSayisi <= 0 || $durum['suresi_doldu']) {
                continue;
            }
            $kalan = MobilSeansKurali::paketKalani($k->seans_sayisi, $k->bekleyen_seans, $hizmetSayisi, $paketKayit[$k->id] ?? 0);
            if ($kalan <= 0 && ! $durum['sinirsiz']) {
                continue;
            }
            if ($durum['sinirsiz']) {
                $kalan = max(1, $kalan); // sinirsizda adet kurali yok
            }

            $ad = $k->paket->paket_adi;
            $icerik = [];
            $hizmetIdleri = [];
            foreach ($k->paket->hizmetler as $h) {
                if (! $h->hizmet) {
                    continue;
                }
                $sure = $h->sure ?? 0;
                $hizmetler[] = ['id' => $h->hizmet_id, 'text' => $h->hizmet->hizmet_adi, 'seans' => $kalan, 'paket_adi' => $ad, 'tur' => 'paket', 'sure' => $sure, 'adisyon_paket_id' => $k->id];
                $icerik[] = ['id' => $h->hizmet_id, 'text' => $h->hizmet->hizmet_adi, 'seans' => $kalan, 'sure' => $sure];
                $hizmetIdleri[] = $h->hizmet_id;
            }
            $detaylar[] = [
                'index' => $sira++, 'adi' => $ad, 'seans' => $kalan, 'tur' => 'Paket: '.$ad, 'sure' => $k->paket->sure ?? 0,
                'hizmet_id' => $hizmetIdleri, 'paket_id' => $k->paket_id, 'type' => 'paket', 'adisyon_paket_id' => $k->id,
                'adisyon_hizmet_id' => null, 'toplamSeans' => $kalan, 'sinirsiz' => $durum['sinirsiz'] ? 1 : 0,
                'gecerlilik' => $durum['sure'], 'icerik' => $icerik,
            ];
        }

        $paketVarMi = $detaylar !== [];

        return [
            'paketRandevuOnayiGerekli' => $paketVarMi && ! $onayVar,
            'onayMetni'                => '',
            'paketHizmetleri'          => $hizmetler,
            'paketDetaylari'           => $detaylar,
            'toplamSeans'              => count($hizmetler),
            'userName'                 => $musteri->name,
            'paketVarMi'               => $paketVarMi,
        ];
    }

    /** NULL ve 1'den farkli otomatik_randevu_olusturuldu "acilmamis" sayilir. */
    private function adisyonlar(string $iliski, int $salonId, int $musteriId): Builder
    {
        return Adisyonlar::whereHas($iliski, fn ($q) => $q->where(fn ($w) => $w->whereNull('otomatik_randevu_olusturuldu')->orWhere('otomatik_randevu_olusturuldu', '!=', 1)))
            ->where('user_id', $musteriId)
            ->where('salon_id', $salonId);
    }

    /** @return array<int,int> kalem id => seans kaydi sayisi */
    private function kayitSayilari(string $kolon, array $kalemIdler): array
    {
        if (! $kalemIdler) {
            return [];
        }

        return DB::table('adisyon_paket_seanslar')->whereIn($kolon, $kalemIdler)->groupBy($kolon)
            ->selectRaw($kolon.' as kalem, COUNT(*) as adet')->pluck('adet', 'kalem')->map(fn ($a) => (int) $a)->all();
    }

    private function bos(string $mesaj): array
    {
        return [
            'paketRandevuOnayiGerekli' => false,
            'onayMetni'                => $mesaj,
            'paketHizmetleri'          => [],
            'paketDetaylari'           => [],
            'toplamSeans'              => 0,
            'paketVarMi'               => false,
        ];
    }
}
