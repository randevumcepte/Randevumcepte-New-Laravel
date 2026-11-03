<?php

namespace App\Services\BildirimReklam;

use App\Domain\BildirimReklam\HedefSegment;
use App\Domain\BildirimReklam\ReklamAksiyonu;
use App\Domain\BildirimReklam\ReklamDurumu;
use App\Domain\BildirimReklam\ReklamTuru;
use App\Services\Satis\KatalogServisi;
use App\Services\Yetki\SubeGrubu;
use Illuminate\Support\Facades\DB;

/**
 * Reklam formu verisi — sözlükler (tür / durum / aksiyon / segment), hizmet + ürün seçenekleri,
 * çoklu şube seçicisi ve bayraklar TEK uçtan. Eski blade bunları sayfaya gömüyordu, mobil v1
 * ayrı hizmetler / urunler uçlarından alıyordu; web + Flutter artık aynı kaynağı okur.
 * Müşteri listesi gömülmez — "belirli kişi" seçimi sunucu aramalıdır (KVKK minimizasyon).
 */
class BildirimReklamFormServisi
{
    public function __construct(
        private KatalogServisi $katalog,
        private SubeGrubu $subeGrubu,
    ) {}

    public function formVerisi(int $salonId, int $yetkiliId, bool $yazabilir): array
    {
        return [
            'turler'     => ReklamTuru::secenekler(),
            'durumlar'   => ReklamDurumu::secenekler(),
            'aksiyonlar' => ReklamAksiyonu::secenekler(),
            'segmentler' => HedefSegment::secenekler(),
            'hizmetler'  => array_map(fn ($h) => ['id' => $h['hizmet_id'], 'ad' => $h['ad']], $this->katalog->hizmetler($salonId)),
            'urunler'    => DB::table('urunler')->where('salon_id', $salonId)->where('aktif', 1)->orderBy('urun_adi')
                ->get(['id', 'urun_adi'])->map(fn ($u) => ['id' => (int) $u->id, 'ad' => trim((string) $u->urun_adi)])->all(),
            // Çoklu şube seçici [{id, ad, secili}] — tek şubeli işletmede boş (çizilmez). Ortak: SubeGrubu.
            'subeler'    => $this->subeGrubu->secenekler($yetkiliId, $salonId),
            'varsayilan' => [
                'tur' => ReklamTuru::KAMPANYA->value, 'aksiyon_tipi' => ReklamAksiyonu::KUPON->value, 'durum' => ReklamDurumu::TASLAK->value,
                'kanal_push' => true, 'kanal_inapp' => true, 'tam_ekran' => false, 'kupon_indirim_tipi' => 'yuzde',
                'kupon_gecerli_tip' => 'tumu', 'kupon_kisi_limit' => 1, 'segment_gun' => HedefSegment::VARSAYILAN_GUN,
            ],
            'gorsel_onerisi' => config('bildirim_reklam.gorsel_onerisi'),
            'yetkiler'   => ['yaz' => $yazabilir],
        ];
    }
}
