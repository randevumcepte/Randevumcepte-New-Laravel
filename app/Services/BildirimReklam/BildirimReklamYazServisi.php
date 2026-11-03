<?php

namespace App\Services\BildirimReklam;

use App\Domain\BildirimReklam\HedefKosulu;
use App\Domain\BildirimReklam\HedefSegment;
use App\Domain\BildirimReklam\ReklamAksiyonu;
use App\Domain\BildirimReklam\ReklamDurumu;
use App\Exceptions\GecersizIslemException;
use App\Services\Yetki\SalonSahipligi;
use App\Services\Yetki\SubeGrubu;
use App\Support\AktiviteLog;
use App\Support\SalonIdListesi;
use Illuminate\Support\Facades\DB;

/**
 * Bildirim reklamı YAZMA — eski bildirim_reklam_kaydet / _durum / _sil (web) ve adminKaydet /
 * adminDurum / adminSil (mobil v1) uçlarının TEK karşılığı (iki kanal birbirinin kopyasıydı).
 *
 * Eskiden farklı olarak: kupon hizmeti / ürünü / segment hizmet-ürün-kişisi istekteki salona ait
 * olmalı (422), görsel yalnız reklam görsel dizininden gelebilir, şube grubu yalnız kullanıcının
 * reklam yetkisi olduğu şubelerden oluşur (mobil v1 sahibin tüm şubelerini kabul ediyordu).
 * Her yazma salon_aktivite_log'a düşer (eski mobil v1 hiç log yazmıyordu).
 */
class BildirimReklamYazServisi
{
    /** Şube grubunda her şube için aranan yetki (BildirimReklamController ile aynı anahtar). */
    public const YETKI = 'pazarlama.kampanya_yonet';

    public function __construct(
        private BildirimReklamSorgusu $sorgu,
        private SalonSahipligi $sahiplik,
        private SubeGrubu $subeGrubu,
    ) {}

    /** @return int reklam id */
    public function kaydet(int $salonId, ?int $id, array $v, int $yetkiliId): int
    {
        $yeniMi = $id === null;
        $mevcutGorsel = $yeniMi ? null : $this->sorgu->satir($id)->gorsel;
        $this->referanslariDenetle($salonId, $v, $mevcutGorsel);
        $kolonlar = $this->kolonlar($v);

        // Şube grubu YALNIZ açık seçim varsa veya yeni reklamda yazılır (düzenlemede korunur).
        // Seçim ∩ kullanıcının bu yetkiye sahip olduğu şubeler; boşsa yalnız bu şube (SubeGrubu).
        if (SalonIdListesi::coz($v['salon_ids'] ?? null) || $yeniMi) {
            $kolonlar['gecerli_salonlar'] = json_encode($this->subeGrubu->hedefler($yetkiliId, $salonId, $v['salon_ids'] ?? null, self::YETKI));
        }

        $kolonlar['updated_at'] = now();
        if ($yeniMi) {
            $id = (int) DB::table('bildirim_reklamlari')->insertGetId($kolonlar + ['salon_id' => $salonId, 'kupon_dagitilan' => 0, 'push_gonderildi' => 0, 'created_at' => now()]);
        } else {
            DB::table('bildirim_reklamlari')->where('id', $id)->where('salon_id', $salonId)->update($kolonlar);
        }

        AktiviteLog::yaz($salonId, $yetkiliId, $yeniMi ? 'bildirim_reklam_olustur' : 'bildirim_reklam_guncelle', 'bildirim_reklam', $id,
            $kolonlar['baslik'], $yeniMi ? 'Bildirim reklamı oluşturuldu' : 'Bildirim reklamı güncellendi');

        return $id;
    }

    public function durumDegistir(int $id, ReklamDurumu $durum, int $yetkiliId): void
    {
        $r = $this->sorgu->satir($id);
        DB::table('bildirim_reklamlari')->where('id', $id)->update(['durum' => $durum->value, 'updated_at' => now()]);
        AktiviteLog::yaz((int) $r->salon_id, $yetkiliId, 'bildirim_reklam_durum', 'bildirim_reklam', $id, (string) $r->baslik,
            'Bildirim reklamı durumu: '.$durum->etiket());
    }

    /** Dağıtılmış kuponlar müşteride kalır (carkifelek_odulleri.kaynak_reklam_id yalnız iz). */
    public function sil(int $id, int $yetkiliId): void
    {
        $r = $this->sorgu->satir($id);
        DB::table('bildirim_reklamlari')->where('id', $id)->delete();
        AktiviteLog::yaz((int) $r->salon_id, $yetkiliId, 'bildirim_reklam_sil', 'bildirim_reklam', $id, (string) $r->baslik, 'Bildirim reklamı silindi');
    }

    /** Doğrulanmış istek → bildirim_reklamlari kolonları (aksiyona göre kupon / pencere alanları temizlenir). */
    private function kolonlar(array $v): array
    {
        $aksiyon = ReklamAksiyonu::from($v['aksiyon_tipi']);
        $k = [
            'baslik'        => trim($v['baslik']),
            'mesaj'         => $v['mesaj'] ?? null,
            'tur'           => $v['tur'],
            'kanal_push'    => (int) $v['kanal_push'],
            'kanal_inapp'   => (int) $v['kanal_inapp'],
            'tam_ekran'     => (int) $v['tam_ekran'],
            'aksiyon_tipi'  => $aksiyon->value,
            'aksiyon_hedef' => $v['aksiyon_hedef'] ?? null,
            'durum'         => $v['durum'],
            'yayin_baslangic' => $v['yayin_baslangic'] ?? null,
            'yayin_bitis'   => $v['yayin_bitis'] ?? null,
        ];
        if (! empty($v['gorsel'])) {
            $k['gorsel'] = $v['gorsel'];
        }

        // Kupon: aksiyon kupon VEYA randevu (randevuda opsiyonel). 'yok'ta eski değerler korunur (eskiyle aynı).
        if ($aksiyon->kuponAlaniVar()) {
            $tip = $v['kupon_gecerli_tip'] ?? 'tumu';
            $k += [
                'kupon_indirim_tipi'   => ($v['kupon_indirim_tipi'] ?? null) === 'tutar' ? 'tutar' : 'yuzde',
                'kupon_deger'          => (float) ($v['kupon_deger'] ?? 0),
                'kupon_baslik'         => $v['kupon_baslik'] ?? null,
                'kupon_gecerlilik_gun' => isset($v['kupon_gecerlilik_gun']) ? (int) $v['kupon_gecerlilik_gun'] : null,
                'kupon_kisi_limit'     => (int) ($v['kupon_kisi_limit'] ?? 1) ?: 1,
                'kupon_toplam_adet'    => isset($v['kupon_toplam_adet']) ? (int) $v['kupon_toplam_adet'] : null,
                // Kupon ya hizmete ya ürüne bağlı (ikisi birden değil); 'tumu' = ikisi de gönderildiği gibi (eski akış).
                'kupon_hizmet_id'      => $tip === 'urun' ? null : ($v['kupon_hizmet_id'] ?? null),
                'kupon_urun_id'        => $tip === 'hizmet' ? null : ($v['kupon_urun_id'] ?? null),
            ];
        }

        $pencere = $aksiyon->randevuPenceresiVar();
        foreach (['randevu_tarih', 'randevu_tarih_bit', 'randevu_saat_bas', 'randevu_saat_bit'] as $alan) {
            $k[$alan] = $pencere ? ($v[$alan] ?? null) : null;
        }

        return $k + $this->hedef($v)->db();
    }

    private function hedef(array $v): HedefKosulu
    {
        if (($v['hedef_kitle'] ?? 'tumu') !== 'segment') {
            return HedefKosulu::tumu();
        }
        $segment = HedefSegment::from($v['segment_tip']);

        return HedefKosulu::segment($segment, match ($segment) {
            HedefSegment::KISI => $v['segment_user_id'] ?? null,
            HedefSegment::GELMEYEN => $v['segment_gun'] ?? null,
            HedefSegment::HIZMET => $v['segment_hizmet_id'] ?? null,
            HedefSegment::URUN => $v['segment_urun_id'] ?? null,
            HedefSegment::CINSIYET => $v['segment_cinsiyet'] ?? 0,
            HedefSegment::DOGUM_GUNU => null,
        });
    }

    /** Gövdedeki hizmet / ürün / kişi / görsel referansları bu salona ait mi. */
    private function referanslariDenetle(int $salonId, array $v, ?string $mevcutGorsel): void
    {
        $segment = ($v['hedef_kitle'] ?? '') === 'segment' ? ($v['segment_tip'] ?? null) : null;

        $hizmetler = array_filter([$v['kupon_hizmet_id'] ?? null, $segment === 'hizmet' ? ($v['segment_hizmet_id'] ?? null) : null]);
        if ($hizmetler) {
            $this->sahiplik->hizmetOlmali(array_map('intval', $hizmetler), $salonId);
        }

        foreach (array_filter([$v['kupon_urun_id'] ?? null, $segment === 'urun' ? ($v['segment_urun_id'] ?? null) : null]) as $urunId) {
            if (! DB::table('urunler')->where('id', (int) $urunId)->where('salon_id', $salonId)->exists()) {
                throw new GecersizIslemException('Seçilen ürün bu işletmeye ait değil.');
            }
        }

        if ($segment === 'kisi' && ! empty($v['segment_user_id'])) {
            $this->sahiplik->musteriOlmali((int) $v['segment_user_id'], $salonId);
        }

        $gorsel = (string) ($v['gorsel'] ?? '');
        if ($gorsel !== '' && $gorsel !== $mevcutGorsel && (! str_starts_with($gorsel, (string) config('bildirim_reklam.gorsel_yol')) || str_contains($gorsel, '..'))) {
            throw new GecersizIslemException('Görsel önce görsel yükleme ucuyla yüklenmelidir.');
        }
    }
}
