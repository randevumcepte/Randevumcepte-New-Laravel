<?php

namespace App\Services\Form;

use App\Domain\Form\SablonSorulari;
use App\Services\Satis\KatalogServisi;
use Illuminate\Support\Facades\DB;

/**
 * Form Yönetimi ekranının modal verileri TEK çağrıda (API-first: istemcide sabit
 * liste yok) — Form Gönder, Sözleşme Oluştur ve Şablon oluşturucu.
 *
 * Eskide bu veriler modal blade'lerinin içinde @php ile sorgulanıyordu
 * (arsiv-form-ekle-modal, sozlesme-olustur-modal).
 */
class FormEkranVerisiServisi
{
    private const ODEME_SEKILLERI = [
        ['kod' => 'pesin', 'ad' => 'Peşin'],
        ['kod' => 'taksit', 'ad' => 'Taksitli'],
        ['kod' => 'kredi_karti', 'ad' => 'Kredi Kartı'],
    ];

    public function __construct(
        private FormSablonServisi $sablon,
        private KatalogServisi $katalog,
    ) {}

    /**
     * @param array{olustur:bool,gonder:bool} $yetkiler  giriş yapanın form yetkileri (buton gate'i)
     */
    public function getir(int $salonId, object $kullanici, array $yetkiler): array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)->first(['yetkili_adi', 'yetkili_telefon']);

        return [
            'yetkiler'        => $yetkiler,
            'taslaklar'       => $this->sablon->secenekler($salonId),
            'hizmetler'       => $this->hizmetler($salonId),
            // Paket fiyat/seans kuralı satış kataloğuyla aynı (tek kaynak).
            'paketler'        => $this->katalog->listele($salonId)['paketler'],
            'odeme_sekilleri' => self::ODEME_SEKILLERI,
            'sozlesme'        => $this->sablon->sozlesmeMetni($salonId) + [
                'yetkili_ad'      => $salon->yetkili_adi ?? $kullanici->name ?? '',
                'yetkili_telefon' => $salon->yetkili_telefon ?? '',
            ],
            'sablon_katalogu' => SablonSorulari::katalog(),
        ];
    }

    /**
     * Salonun hizmetleri. `id` = salon_sunulan_hizmetler.id (sözleşme arsiv.hizmet_id ve
     * sözleşme PDF'i bunu bekler); `hizmet_id` = hizmetler.id (form 12 ek alanı eskide bunu yazıyordu).
     */
    private function hizmetler(int $salonId): array
    {
        return DB::table('salon_sunulan_hizmetler as sh')
            ->join('hizmetler as h', 'h.id', '=', 'sh.hizmet_id')
            ->where('sh.salon_id', $salonId)->where('sh.aktif', 1)
            ->orderBy('h.hizmet_adi')
            ->get(['sh.id', 'sh.hizmet_id', 'h.hizmet_adi', DB::raw('COALESCE(sh.son_fiyat, sh.baslangic_fiyat) as fiyat')])
            ->map(fn ($h) => [
                'id'        => (int) $h->id,
                'hizmet_id' => (int) $h->hizmet_id,
                'ad'        => trim((string) $h->hizmet_adi),
                'fiyat'     => $h->fiyat !== null ? (float) $h->fiyat : null,
            ])->all();
    }
}
