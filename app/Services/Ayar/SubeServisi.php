<?php

namespace App\Services\Ayar;

use App\Services\Yetki\KapsamServisi;
use App\Support\EskiPanelLinki;
use App\Support\YerelListe;
use Illuminate\Support\Facades\DB;

/**
 * Ayarlar → Subeler (OKUMA) — eski ayarlar() $subeler tablosunun karsiligi: kullanicinin eristigi
 * salonlar (KapsamServisi — panel kabugundaki sube seciciyle AYNI kaynak).
 *
 * Yazma v2'ye TASINMADI: yeni sube (isletme + yetkili + sifre SMS'i) eski panelde kalir
 * (yeni_sube_url); eski aktif/pasif dugmesi `subeler` tablosuna salon id'siyle yazdigi icin
 * hicbir subeyi gercekte kapatmiyordu — dogru anlami netlesmeden tasinmaz.
 */
class SubeServisi
{
    public function __construct(private KapsamServisi $kapsam) {}

    /** @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $o */
    public function listele(int $yetkiliId, int $aktifSalonId, array $o): array
    {
        $idler = $this->kapsam->kullaniciSalonlari($yetkiliId) ?: [$aktifSalonId];
        $bugun = new \DateTimeImmutable('today');

        $satirlar = DB::table('salonlar')->whereIn('id', $idler)->orderBy('salon_adi')
            ->get(['id', 'salon_adi', 'adres', 'telefon_1', 'uyelik_bitis_tarihi'])
            ->map(function ($s) use ($aktifSalonId, $bugun) {
                $bitis = $s->uyelik_bitis_tarihi ? substr((string) $s->uyelik_bitis_tarihi, 0, 10) : null;

                return [
                    'id'                  => (int) $s->id,
                    'salon_adi'           => trim((string) $s->salon_adi),
                    'adres'               => $s->adres,
                    'telefon_1'           => $s->telefon_1,
                    'uyelik_bitis_tarihi' => $bitis,
                    'kalan_gun'           => $bitis ? (int) $bugun->diff(new \DateTimeImmutable($bitis))->format('%r%a') : null,
                    'aktif_sube'          => (int) $s->id === $aktifSalonId,
                ];
            })->all();

        return YerelListe::uygula($satirlar, $o, ['salon_adi', 'adres', 'telefon_1'])
            + ['yeni_sube_url' => EskiPanelLinki::url('isletmeyonetim/ayarlar', ['p' => 'subeler', 'sube' => $aktifSalonId])];
    }
}
