<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaRandevuDurumu;
use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\CagriMerkeziSekmeleri;
use App\Domain\CagriMerkezi\ListeDurumu;
use Illuminate\Support\Facades\DB;

/**
 * Çağrı Merkezi AÇILIŞ verisi — tek istekte ekran kurgusu: erişim bayrakları, görünür sekmeler,
 * sonuç / takvim sözlükleri, görüşme scriptleri, sonuç kategori ağacı, atanabilir personeller.
 * Web ve Flutter hiçbir etiket/renk/sekme sabitlemez; hepsi buradan.
 */
class CagriReferansServisi
{
    public function acilis(CagriMerkeziErisim $e): array
    {
        return [
            'erisim'          => $e->dizi(),
            'sekmeler'        => CagriMerkeziSekmeleri::gorunur($e),
            'sozluk'          => AramaSonucu::sozluk() + ['takvim' => AramaRandevuDurumu::lejand(), 'liste_durumlari' => ListeDurumu::secenekler()],
            'scriptler'       => $this->scriptler($e->salonId),
            'kategoriler'     => $this->kategoriler($e->salonId),
            'personeller'     => $this->personeller($e),
            'salon_adi'       => (string) DB::table('salonlar')->where('id', $e->salonId)->value('salon_adi'),
        ];
    }

    /** Yöneticinin tanımladığı aktif görüşme scriptleri (eski cagri_scriptleri_getir). */
    public function scriptler(int $salonId): array
    {
        return DB::table('cagri_scriptleri')->where('salon_id', $salonId)->where('aktif', 1)->orderBy('baslik')
            ->get(['id', 'baslik', 'icerik'])->map(fn ($s) => ['id' => (int) $s->id, 'baslik' => (string) $s->baslik, 'icerik' => (string) $s->icerik])->all();
    }

    /** Sonuç kategori ağacı: [{id, ad, altlar:[{id, ad}]}] (eski cagri_kategori_liste). */
    public function kategoriler(int $salonId): array
    {
        $hepsi = DB::table('cagri_kategorileri')->where('salon_id', $salonId)->where('aktif', 1)->orderBy('sira')->orderBy('id')->get(['id', 'ust_id', 'ad']);

        return $hepsi->whereNull('ust_id')->map(fn ($ana) => [
            'id'     => (int) $ana->id,
            'ad'     => (string) $ana->ad,
            'altlar' => $hepsi->where('ust_id', $ana->id)->map(fn ($a) => ['id' => (int) $a->id, 'ad' => (string) $a->ad])->values()->all(),
        ])->values()->all();
    }

    /**
     * Atanabilir personeller (giriş hesabı olan aktif personel). Personel yalnız kendini görür —
     * arama randevusunu / listeyi başkasına veremez.
     */
    public function personeller(CagriMerkeziErisim $e): array
    {
        $q = DB::table('salon_personelleri')->where('salon_id', $e->salonId)->where('aktif', 1)->whereNotNull('yetkili_id');
        if (! $e->yonetici) {
            $q->where('id', $e->personelId ?? 0);
        }

        return $q->orderBy('personel_adi')->get(['id', 'personel_adi'])->map(fn ($p) => ['id' => (int) $p->id, 'ad' => (string) $p->personel_adi])->all();
    }
}
