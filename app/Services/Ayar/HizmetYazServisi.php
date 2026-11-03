<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\TakvimRengi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Ayarlar → Hizmetler YAZMA — eski hizmetekleduzenle (havuzdan ekle), sistemeyenihizmetekle
 * (ozel hizmet), hizmetYonetimiGuncelle, salonhizmetsil, hizmetKategoriEkle'nin TEK karsiligi.
 *
 * Kurallar (eskiyle ayni):
 *  - Havuz hizmetinin adi/kategorisi/cinsiyeti degisirse havuz kaydi BOZULMAZ: salona ozel kopya acilir,
 *    salon kaydi ve bu salonun personel/cihaz atamalari kopyaya tasinir.
 *  - Kategori ilk kez kullaniliyorsa salona takvim rengi atanir (10'lu dongu).
 *  - Kaldirma = salon_sunulan_hizmetler.aktif = 0.
 * Eskiden farkli: havuzdan eklemede pasif kayit YALNIZ bu salonda aranir (eski kod baska salonun
 * satirini alip bu salona tasiyordu) ve uc yetki ister; personel/cihaz kimlikleri salona suzulur;
 * ayni adli global kategori varsa yenisi acilmaz.
 */
class HizmetYazServisi
{
    public function __construct(private HizmetOkumaServisi $okuma) {}

    /**
     * Havuzdan (sistem / salonun pasif ozel hizmetleri) toplu ekle.
     *
     * @param  list<array{hizmet_id:int, fiyat?:?float, sure_dk?:?int, personel_ids?:list<int>, cihaz_ids?:list<int>}>  $kalemler
     */
    public function havuzdanEkle(int $salonId, int $yapanId, array $kalemler): array
    {
        $havuz = collect($this->okuma->havuz($salonId))->flatMap(fn ($g) => array_map(fn ($h) => $h + ['kategori_id' => $g['kategori_id']], $g['hizmetler']))->keyBy('id');
        $gecersiz = array_filter($kalemler, fn ($k) => ! $havuz->has((int) $k['hizmet_id']));
        if ($gecersiz) {
            throw new GecersizIslemException('Seçilen hizmetlerden bazıları eklenebilir değil (zaten ekli ya da başka işletmeye ait).');
        }

        $eklenen = DB::transaction(function () use ($salonId, $kalemler, $havuz) {
            $idler = [];
            foreach ($kalemler as $k) {
                $hizmetId = (int) $k['hizmet_id'];
                $kategoriId = $havuz[$hizmetId]['kategori_id'];
                $alanlar = [
                    'baslangic_fiyat' => (float) ($k['fiyat'] ?? 0), 'sure_dk' => $k['sure_dk'] ?? $havuz[$hizmetId]['sure_dk'],
                    'hizmet_kategori_id' => $kategoriId, 'aktif' => 1, 'bolum' => 0, 'updated_at' => now(),
                ];
                $mevcut = DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $hizmetId)->orderByDesc('id')->value('id');
                if ($mevcut) {
                    DB::table('salon_sunulan_hizmetler')->where('id', $mevcut)->update($alanlar);
                    $idler[] = (int) $mevcut;
                } else {
                    $idler[] = (int) DB::table('salon_sunulan_hizmetler')->insertGetId($alanlar + ['salon_id' => $salonId, 'hizmet_id' => $hizmetId, 'created_at' => now()]);
                }
                $this->kategoriRengiAta($salonId, $kategoriId);
                $this->atamaYaz($salonId, $hizmetId, $k['personel_ids'] ?? [], $k['cihaz_ids'] ?? []);
            }

            return $idler;
        });

        AktiviteLog::yaz($salonId, $yapanId, 'hizmet_ekle', 'hizmet', null, count($eklenen).' hizmet',
            'Hizmet(-ler) havuzdan eklendi', ['hizmet_idler' => array_column($kalemler, 'hizmet_id')]);

        return ['eklenen' => count($eklenen)];
    }

    /**
     * Salona ozel yeni hizmet (hizmetler + salon_sunulan_hizmetler).
     *
     * @param  array{hizmet_adi:string, kategori_id:int, sure_dk?:?int, fiyat?:?float, maliyet?:?float, cinsiyet?:?int, personel_ids?:list<int>, cihaz_ids?:list<int>}  $g
     */
    public function yeniEkle(int $salonId, int $yapanId, array $g): array
    {
        $id = DB::transaction(function () use ($salonId, $g) {
            $simdi = now();
            $hizmetId = (int) DB::table('hizmetler')->insertGetId([
                'hizmet_adi' => trim($g['hizmet_adi']), 'hizmet_kategori_id' => (int) $g['kategori_id'], 'ozel_hizmet' => 1,
                'salon_id' => $salonId, 'cinsiyet' => $g['cinsiyet'] ?? null, 'sure_dk' => $g['sure_dk'] ?? null,
                'created_at' => $simdi, 'updated_at' => $simdi,
            ]);
            $id = (int) DB::table('salon_sunulan_hizmetler')->insertGetId([
                'salon_id' => $salonId, 'hizmet_id' => $hizmetId, 'hizmet_kategori_id' => (int) $g['kategori_id'],
                'bolum' => $g['cinsiyet'] ?? 0, 'baslangic_fiyat' => (float) ($g['fiyat'] ?? 0), 'maliyet' => (float) ($g['maliyet'] ?? 0),
                'sure_dk' => $g['sure_dk'] ?? null, 'aktif' => 1, 'created_at' => $simdi, 'updated_at' => $simdi,
            ]);
            $this->kategoriRengiAta($salonId, (int) $g['kategori_id']);
            $this->atamaYaz($salonId, $hizmetId, $g['personel_ids'] ?? [], $g['cihaz_ids'] ?? []);

            return $id;
        });

        $detay = $this->okuma->detay($salonId, $id);
        AktiviteLog::yaz($salonId, $yapanId, 'hizmet_ekle', 'salon_hizmet', $id, $detay['hizmet_adi'], 'Yeni hizmet eklendi',
            ['fiyat' => $detay['fiyat'], 'sure_dk' => $detay['sure_dk'], 'kategori_id' => $detay['kategori_id']]);

        return $detay;
    }

    /** Kismi guncelleme (gonderilen alanlar). $id = salon_sunulan_hizmetler.id */
    public function guncelle(int $salonId, int $yapanId, int $id, array $g): array
    {
        $eski = $this->okuma->detay($salonId, $id);

        DB::transaction(function () use ($salonId, $id, $g, $eski) {
            $hizmetId = $eski['hizmet_id'];
            $ad = isset($g['hizmet_adi']) ? trim($g['hizmet_adi']) : null;
            $adDegisti = $ad !== null && $ad !== '' && $ad !== $eski['hizmet_adi'];
            $katDegisti = isset($g['kategori_id']) && (int) $g['kategori_id'] !== $eski['kategori_id'];
            $cins = array_key_exists('cinsiyet', $g) && $g['cinsiyet'] !== null && $g['cinsiyet'] !== '' ? (int) $g['cinsiyet'] : null;
            $cinsDegisti = array_key_exists('cinsiyet', $g) && $cins !== $eski['cinsiyet'];

            // Katalog alanlari (ad/kategori/cinsiyet) GLOBAL hizmetler kaydinda: havuz hizmetinde
            // degisirse diger salonlar etkilenmesin diye once salona ozel kopya (eski kod cinsiyeti
            // dogrudan havuz kaydina yaziyordu).
            if (! $eski['ozel_hizmet'] && ($adDegisti || $katDegisti || $cinsDegisti)) {
                $hizmetId = $this->salonaOzelKopya($salonId, $id, $hizmetId);
            }

            $hizmet = array_filter([
                'hizmet_adi' => $adDegisti ? $ad : null,
                'hizmet_kategori_id' => $katDegisti ? (int) $g['kategori_id'] : null,
            ], fn ($v) => $v !== null);
            if ($cinsDegisti) {
                $hizmet['cinsiyet'] = $cins;
            }
            if ($hizmet) {
                DB::table('hizmetler')->where('id', $hizmetId)->update($hizmet + ['updated_at' => now()]);
            }

            $salon = [];
            if (array_key_exists('fiyat', $g)) {
                $salon['baslangic_fiyat'] = (float) ($g['fiyat'] ?? 0);
            }
            if (array_key_exists('maliyet', $g)) {
                $salon['maliyet'] = (float) ($g['maliyet'] ?? 0);
            }
            if (array_key_exists('sure_dk', $g)) {
                $salon['sure_dk'] = $g['sure_dk'];
            }
            if ($katDegisti) {
                $salon['hizmet_kategori_id'] = (int) $g['kategori_id'];
            }
            if ($salon) {
                DB::table('salon_sunulan_hizmetler')->where('id', $id)->update($salon + ['updated_at' => now()]);
            }
            if ($katDegisti) {
                $this->kategoriRengiAta($salonId, (int) $g['kategori_id']);
            }
            if (array_key_exists('personel_ids', $g) || array_key_exists('cihaz_ids', $g)) {
                $this->atamaYaz($salonId, $hizmetId, $g['personel_ids'] ?? array_column($eski['personeller'], 'id'),
                    $g['cihaz_ids'] ?? array_column($eski['cihazlar'], 'id'));
            }
        });

        $detay = $this->okuma->detay($salonId, $id);
        AktiviteLog::yaz($salonId, $yapanId, 'hizmet_yonetim_guncelle', 'salon_hizmet', $id,
            $detay['hizmet_adi'].' — '.number_format($detay['fiyat'], 2, ',', '.').' ₺',
            'Hizmet yönetimi güncellendi (fiyat / süre / personel / kategori)',
            ['hizmet_id' => $detay['hizmet_id'], 'fiyat' => $detay['fiyat'], 'sure_dk' => $detay['sure_dk'], 'kategori_id' => $detay['kategori_id']]);

        return $detay;
    }

    public function kaldir(int $salonId, int $yapanId, int $id): void
    {
        $eski = $this->okuma->detay($salonId, $id);
        DB::table('salon_sunulan_hizmetler')->where('id', $id)->update(['aktif' => 0, 'updated_at' => now()]);

        AktiviteLog::yaz($salonId, $yapanId, 'hizmet_kaldir', 'salon_hizmet', $id, $eski['hizmet_adi'], 'Hizmet katalogdan kaldırıldı (pasif)');
    }

    /** Global kategori ekle (hizmet_kategorisi salon_id'siz — eski sema); ayni ad varsa onu dondurur. */
    public function kategoriEkle(int $salonId, int $yapanId, string $ad): array
    {
        $ad = trim(preg_replace('/\s+/u', ' ', $ad));
        $mevcut = DB::table('hizmet_kategorisi')->whereRaw('LOWER(TRIM(hizmet_kategorisi_adi)) = ?', [mb_strtolower($ad)])->value('id');
        if ($mevcut) {
            return ['id' => (int) $mevcut, 'ad' => $ad, 'yeni' => false];
        }
        $id = (int) DB::table('hizmet_kategorisi')->insertGetId(['hizmet_kategorisi_adi' => $ad, 'created_at' => now(), 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yapanId, 'hizmet_kategori_ekle', 'hizmet_kategori', $id, $ad, 'Yeni hizmet kategorisi eklendi');

        return ['id' => $id, 'ad' => $ad, 'yeni' => true];
    }

    // ───────────────────────── yardimcilar ─────────────────────────

    /** Havuz kaydini kopyala (ozel + bu salon), salon kaydini ve bu salonun atamalarini kopyaya tasi. */
    private function salonaOzelKopya(int $salonId, int $salonHizmetId, int $hizmetId): int
    {
        $kaynak = (array) DB::table('hizmetler')->where('id', $hizmetId)->first();
        if (! $kaynak) {
            throw new BulunamadiException('Hizmet bulunamadı');
        }
        unset($kaynak['id']);
        $kopyaId = (int) DB::table('hizmetler')->insertGetId(array_merge($kaynak, [
            'ozel_hizmet' => 1, 'salon_id' => $salonId, 'created_at' => now(), 'updated_at' => now(),
        ]));
        DB::table('salon_sunulan_hizmetler')->where('id', $salonHizmetId)->update(['hizmet_id' => $kopyaId]);
        DB::table('personel_sunulan_hizmetler')->where('hizmet_id', $hizmetId)
            ->whereIn('personel_id', DB::table('salon_personelleri')->where('salon_id', $salonId)->select('id'))
            ->update(['hizmet_id' => $kopyaId]);
        DB::table('cihaz_sunulan_hizmetler')->where('hizmet_id', $hizmetId)
            ->whereIn('cihaz_id', DB::table('cihazlar')->where('salon_id', $salonId)->select('id'))
            ->update(['hizmet_id' => $kopyaId]);

        return $kopyaId;
    }

    /**
     * Hizmetin BU salondaki personel + cihaz atamalarini sil-ve-yaz (hizmet_id global katalog
     * kimligi: baska salonlarin atamalarina dokunulmaz). Salona ait olmayan kimlikler suzulur.
     */
    private function atamaYaz(int $salonId, int $hizmetId, array $personelIds, array $cihazIds): void
    {
        $salonPersonel = DB::table('salon_personelleri')->where('salon_id', $salonId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $salonCihaz = DB::table('cihazlar')->where('salon_id', $salonId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $simdi = now();

        DB::table('personel_sunulan_hizmetler')->where('hizmet_id', $hizmetId)->whereIn('personel_id', $salonPersonel ?: [0])->delete();
        DB::table('cihaz_sunulan_hizmetler')->where('hizmet_id', $hizmetId)->whereIn('cihaz_id', $salonCihaz ?: [0])->delete();

        $p = array_values(array_intersect(array_unique(array_map('intval', $personelIds)), $salonPersonel));
        $c = array_values(array_intersect(array_unique(array_map('intval', $cihazIds)), $salonCihaz));
        if ($p) {
            DB::table('personel_sunulan_hizmetler')->insert(array_map(fn ($id) => ['personel_id' => $id, 'hizmet_id' => $hizmetId, 'created_at' => $simdi, 'updated_at' => $simdi], $p));
        }
        if ($c) {
            DB::table('cihaz_sunulan_hizmetler')->insert(array_map(fn ($id) => ['cihaz_id' => $id, 'hizmet_id' => $hizmetId, 'created_at' => $simdi, 'updated_at' => $simdi], $c));
        }
    }

    /** Kategori bu salonda ilk kez kullaniliyorsa takvim rengi ata (eskiyle ayni: en buyuk renk_id + 1). */
    private function kategoriRengiAta(int $salonId, ?int $kategoriId): void
    {
        if (! $kategoriId || DB::table('salon_hizmet_kategori_renkleri')->where('salon_id', $salonId)->where('hizmet_kategori_id', $kategoriId)->exists()) {
            return;
        }
        $son = DB::table('salon_hizmet_kategori_renkleri')->where('salon_id', $salonId)->max('renk_id');
        DB::table('salon_hizmet_kategori_renkleri')->insert([
            'salon_id' => $salonId, 'hizmet_kategori_id' => $kategoriId, 'renk_id' => TakvimRengi::sonraki($son !== null ? (int) $son : null),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
