<?php

namespace App\Services\Seans;

use App\Domain\Seans\LazerTespiti;
use App\Domain\Seans\SeansKalemTuru;
use App\Exceptions\GecersizIslemException;
use App\Models\SeansCihazVerileri;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Lazer epilasyon seanslarinda cihaz parametreleri (Enerji/Hiz/MS/Atis + uygulayan
 * personel). Eski web + v1 mobil seansCihazVeriGetir/Kaydet'in TEK karsiligi.
 * Seans basina bolge satirlari (hasMany); kayit "tam yenile" (sil + yaz) — eskiyle ayni.
 */
class SeansCihazServisi
{
    private const PARAMETRELER = ['enerji', 'hiz', 'ms', 'atis_sayisi', 'notlar'];

    /** Seans lazer mi? (hizmet adi ya da paketin adi). */
    public function lazerMi(object $seans, SeansKalemTuru $tur, object $kalem): bool
    {
        $paketAdi = $tur === SeansKalemTuru::PAKET
            ? DB::table('paketler')->where('id', $kalem->katalog_id)->value('paket_adi')
            : null;

        return LazerTespiti::adlardanBiri($this->hizmetAdi($seans), $paketAdi);
    }

    public function getir(object $seans, SeansKalemTuru $tur, object $kalem): array
    {
        $this->lazerZorunlu($seans, $tur, $kalem);
        $salonId = (int) $kalem->salon_id;

        return [
            'seans' => [
                'id'                     => (int) $seans->id,
                'seans_no'               => $seans->seans_no !== null ? (int) $seans->seans_no : null,
                'tarih'                  => $seans->seans_tarih,
                'hizmet_adi'             => $this->hizmetAdi($seans),
                'musteri_adi'            => (string) DB::table('users')->where('id', $kalem->user_id)->value('name'),
                'varsayilan_personel_id' => $this->varsayilanPersonel($seans),
            ],
            'personeller' => DB::table('salon_personelleri')->where('salon_id', $salonId)
                ->whereRaw('COALESCE(arsivli,0) = 0')->orderBy('personel_adi')
                ->get(['id', 'personel_adi as ad'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'ad' => (string) $p->ad])->all(),
            'bolgeler' => SeansCihazVerileri::where('seans_id', $seans->id)->orderBy('id')
                ->get(['id', 'uygulama_bolgesi', 'personel_id', ...self::PARAMETRELER])->toArray(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $bolgeler  [{uygulama_bolgesi?, enerji, hiz, ms, atis_sayisi, personel_id, notlar}]
     */
    public function kaydet(object $seans, SeansKalemTuru $tur, object $kalem, array $bolgeler, ?int $yapanId = null): int
    {
        $this->lazerZorunlu($seans, $tur, $kalem);
        $salonId = (int) $kalem->salon_id;
        $varsayilanBolge = $this->hizmetAdi($seans);

        $personeller = array_filter(array_map(fn ($b) => (int) ($b['personel_id'] ?? 0), $bolgeler));
        if ($personeller && DB::table('salon_personelleri')->where('salon_id', $salonId)->whereIn('id', $personeller)->count() !== count(array_unique($personeller))) {
            throw new GecersizIslemException('Seçilen personel bu işletmeye ait değil');
        }

        $satirlar = [];
        foreach ($bolgeler as $b) {
            $deger = fn (string $alan) => ($v = trim((string) ($b[$alan] ?? ''))) === '' ? null : $v;
            $parametreler = array_combine(self::PARAMETRELER, array_map($deger, self::PARAMETRELER));
            // Parametrelerin tamami bossa satir yazilmaz (bolge adi otomatik oldugu icin ona bakilmaz).
            if (! array_filter($parametreler, fn ($v) => $v !== null)) {
                continue;
            }
            $satirlar[] = $parametreler + [
                'seans_id'         => (int) $seans->id,
                'salon_id'         => $salonId,
                'personel_id'      => ((int) ($b['personel_id'] ?? 0)) ?: null,
                'uygulama_bolgesi' => $deger('uygulama_bolgesi') ?? ($varsayilanBolge ?: null),
                'tarih'            => $seans->seans_tarih,
            ];
        }

        DB::transaction(function () use ($seans, $satirlar) {
            SeansCihazVerileri::where('seans_id', $seans->id)->delete();
            foreach ($satirlar as $s) {
                SeansCihazVerileri::create($s);
            }
        });

        AktiviteLog::yaz($salonId, $yapanId, 'seans_cihaz_kaydet', 'seans', (int) $seans->id, null, 'Cihaz bilgileri kaydedildi');

        return count($satirlar);
    }

    /**
     * Cihaz verisi girilmis seans id'leri (liste ekranindaki dokum ikonu icin TOPLU).
     *
     * @param  int[]  $seansIdler
     * @return array<int,true>
     */
    public function veriOlanSeanslar(array $seansIdler): array
    {
        if (! $seansIdler) {
            return [];
        }

        return array_fill_keys(
            SeansCihazVerileri::whereIn('seans_id', $seansIdler)->distinct()->pluck('seans_id')->map(fn ($id) => (int) $id)->all(),
            true,
        );
    }

    /**
     * A4 seans dokumu PDF'i (eski panelde uretilir; v2'de PDF motoru yok). Link sunucudan
     * gelir ki web ve Flutter ayni adresi kullansin.
     */
    public function dokumUrl(SeansKalemTuru $tur, int $kalemId, int $salonId): string
    {
        $alan = $tur === SeansKalemTuru::PAKET ? 'adisyonpaketid' : 'adisyonhizmetid';

        return rtrim((string) config('panel.eski_panel_url'), '/').'/isletmeyonetim/seansDokumuPdf?'
            .http_build_query([$alan => $kalemId, 'sube' => $salonId]);
    }

    private function lazerZorunlu(object $seans, SeansKalemTuru $tur, object $kalem): void
    {
        if (! $this->lazerMi($seans, $tur, $kalem)) {
            throw new GecersizIslemException('Bu hizmet lazer epilasyon değil; cihaz takibi yapılmaz.');
        }
    }

    private function hizmetAdi(object $seans): string
    {
        return (string) DB::table('hizmetler')->where('id', $seans->hizmet_id)->value('hizmet_adi');
    }

    /** Uygulayan varsayilani: seansin personeli, yoksa bagli randevudaki o hizmetin personeli. */
    private function varsayilanPersonel(object $seans): ?int
    {
        $id = $seans->personel_id ?: ($seans->randevu_id
            ? DB::table('randevu_hizmetler')->where('randevu_id', $seans->randevu_id)->where('hizmet_id', $seans->hizmet_id)->value('personel_id')
            : null);

        return $id ? (int) $id : null;
    }
}
