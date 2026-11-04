<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\DosyaYukleme;
use App\Support\EskiPanelLinki;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Isletme logosu + galeri gorselleri — eski isletmelogoyukle / isletmegorselekle /
 * gorselsil ve v1 isletmelogoyukle'nin TEK karsiligi. Dosyalar eski monolitin
 * public/salon_gorselleri dizinine yazilir; DB yol bicimi eskiyle ayni (config/ayar.php).
 *
 * Eskiden farkli olarak: gorsel silme salon kapsamina baglidir (eskide yetkisiz ve
 * kapsamsizdi), ayni adli logolar birbirini ezmez.
 */
class IsletmeGorselServisi
{
    private const DIZIN_GORELI = 'public/salon_gorselleri/';

    /** Mutlak gorsel URL'i (eski panel host'u) — istemciler host birlestirmez. */
    public function url(?string $yol): ?string
    {
        return EskiPanelLinki::mutlak($yol);
    }

    /** @return array{kalan:int, maks:int, gorseller:list<array{id:int,salon_gorseli:string,url:?string}>} */
    public function galeri(int $salonId): array
    {
        $gorseller = $this->galeriSorgu($salonId)->orderBy('id')->get(['id', 'salon_gorseli'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'salon_gorseli' => (string) $g->salon_gorseli, 'url' => $this->url($g->salon_gorseli)])
            ->all();

        return ['kalan' => max(0, AyarSozluk::GALERI_MAKS - count($gorseller)), 'maks' => AyarSozluk::GALERI_MAKS, 'gorseller' => $gorseller];
    }

    /** @return array{logo:string, logo_url:?string} */
    public function logoYukle(int $salonId, int $yapanId, ?UploadedFile $dosya): array
    {
        $ad = $this->kaydet($dosya, 'logo');
        $logo = self::DIZIN_GORELI.$ad;
        DB::table('salonlar')->where('id', $salonId)->update(['logo' => $logo]);

        AktiviteLog::yaz($salonId, $yapanId, 'isletme_logo_guncelle', 'salon', $salonId,
            (string) DB::table('salonlar')->where('id', $salonId)->value('salon_adi'), 'İşletme logosu güncellendi');

        return ['logo' => $logo, 'logo_url' => $this->url($logo)];
    }

    /**
     * Kalan slot kadarini yukler (fazlasi sessizce atlanmaz → hata), her gorsel 720px yukseklige kucultulur.
     *
     * @param  list<UploadedFile>  $dosyalar
     */
    public function galeriEkle(int $salonId, int $yapanId, array $dosyalar): array
    {
        $kalan = $this->galeri($salonId)['kalan'];
        if (! $dosyalar) {
            throw new GecersizIslemException('Yüklenecek görseli seçiniz.');
        }
        if (count($dosyalar) > $kalan) {
            throw new GecersizIslemException("En fazla {$kalan} görsel daha ekleyebilirsiniz (toplam ".AyarSozluk::GALERI_MAKS.').');
        }

        $simdi = now();
        foreach ($dosyalar as $dosya) {
            $ad = $this->kaydet($dosya, 'gorsel');
            DosyaYukleme::gorselKucult(rtrim((string) config('ayar.gorsel_dizin'), '/').'/'.$ad, (int) config('ayar.galeri_yukseklik_px'));
            DB::table('salon_gorselleri')->insert([
                'salon_id' => $salonId, 'salon_gorseli' => '/'.self::DIZIN_GORELI.$ad, 'kapak_fotografi' => 0,
                'created_at' => $simdi, 'updated_at' => $simdi,
            ]);
        }

        AktiviteLog::yaz($salonId, $yapanId, 'isletme_gorsel_ekle', 'salon', $salonId,
            count($dosyalar).' görsel', 'İşletme görselleri eklendi');

        return $this->galeri($salonId);
    }

    public function galeriSil(int $salonId, int $yapanId, int $gorselId): array
    {
        $gorsel = $this->galeriSorgu($salonId)->where('id', $gorselId)->first(['id', 'salon_gorseli']);
        if (! $gorsel) {
            throw new BulunamadiException('Görsel bulunamadı');
        }
        DB::table('salon_gorselleri')->where('id', $gorselId)->delete();
        DosyaYukleme::sil((string) config('ayar.eski_public_kok'), $gorsel->salon_gorseli);

        AktiviteLog::yaz($salonId, $yapanId, 'isletme_gorsel_sil', 'salon_gorseli', $gorselId,
            (string) $gorsel->salon_gorseli, 'İşletme görseli silindi');

        return $this->galeri($salonId);
    }

    /** Kapak fotografi haric galeri (NULL kapak = galeri; NULL != 1 tuzagina dusmez). */
    private function galeriSorgu(int $salonId)
    {
        return DB::table('salon_gorselleri')->where('salon_id', $salonId)->whereRaw('COALESCE(kapak_fotografi, 0) != 1');
    }

    private function kaydet(?UploadedFile $dosya, string $kok): string
    {
        return DosyaYukleme::kaydet($dosya, (string) config('ayar.gorsel_dizin'),
            DosyaYukleme::GORSEL_UZANTILARI, (int) config('ayar.gorsel_maks_kb'), $kok);
    }
}
