<?php

namespace App\Services\Satis;

use App\Exceptions\BulunamadiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satış Takibi satır işlemleri — eski StoreAdminController metotlarının aynası
 * (web + Flutter AYNI uç; kod tekrarı yok):
 *   - faturaIsaretle  ← adisyonFaturaIsaretle  (toggle fatura_kesildi)
 *   - studyoOdeme     ← adisyonOdemeIsaretle   (Adisyonlar::tamOde / odemeGeriAl)
 *   - faturasizGizle  ← faturasizGizleToggle   (salon geneli ayar)
 * Yetki kararları controller'da (birebir eski kurallar); burada yalnız veri işi.
 */
class AdisyonIslemServisi
{
    /** Salon personel kaydı (salon_personelleri) — eski kodun hesap sahibi/oluşturan kaynağı. */
    public function personel(int $yetkiliId, int $salonId): ?object
    {
        return DB::table('salon_personelleri')->where('salon_id', $salonId)
            ->where('yetkili_id', $yetkiliId)->first(['id', 'role_id']);
    }

    /**
     * Fatura butonunu GÖSTERME kuralı (eski liste): personel role_id=1 VEYA
     * model_has_roles role_id=1. (İşlem yetkisi daha dar: yalnız personel role_id=1.)
     */
    public function hesapSahibiGorunur(int $yetkiliId, int $salonId): bool
    {
        if ((int) ($this->personel($yetkiliId, $salonId)->role_id ?? 0) === 1) {
            return true;
        }

        return DB::table('model_has_roles')->where('role_id', 1)
            ->where('model_id', $yetkiliId)->where('salon_id', $salonId)->exists();
    }

    /** İşlem kuralı (eski adisyonFaturaIsaretle / faturasizGizleToggle): personel role_id === 1. */
    public function hesapSahibiIslem(int $yetkiliId, int $salonId): bool
    {
        return (int) ($this->personel($yetkiliId, $salonId)->role_id ?? 0) === 1;
    }

    public function adisyonSalonId(int $adisyonId): ?int
    {
        $s = DB::table('adisyonlar')->where('id', $adisyonId)->value('salon_id');

        return $s ? (int) $s : null;
    }

    public function studyoModu(int $salonId): bool
    {
        return (int) DB::table('salonlar')->where('id', $salonId)->value('studyo_modu') === 1;
    }

    /** fatura_kesildi'yi tersine çevirir; kesildiyse tarih + kesen personel yazılır. */
    public function faturaIsaretle(int $adisyonId, int $salonId, ?int $personelId): array
    {
        $a = DB::table('adisyonlar')->where('id', $adisyonId)->where('salon_id', $salonId)->first(['id', 'fatura_kesildi']);
        if (! $a) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }
        $yeni = ! ((bool) $a->fatura_kesildi);
        DB::table('adisyonlar')->where('id', $adisyonId)->update([
            'fatura_kesildi'           => $yeni ? 1 : 0,
            'fatura_kesildi_tarihi'    => $yeni ? now() : null,
            'fatura_kesen_personel_id' => $yeni ? $personelId : null,
        ]);

        return ['adisyon_id' => $adisyonId, 'fatura_kesildi' => $yeni ? 1 : 0];
    }

    /** Salon geneli faturasız gizle ayarını tersine çevirir. */
    public function faturasizGizle(int $salonId): array
    {
        $mevcut = (bool) DB::table('salonlar')->where('id', $salonId)->value('faturasiz_gizle');
        DB::table('salonlar')->where('id', $salonId)->update(['faturasiz_gizle' => $mevcut ? 0 : 1]);

        return ['faturasiz_gizle' => $mevcut ? 0 : 1];
    }

    /**
     * Stüdyo "Ödeme Alındı" (alindi=true) → kalan tutar tam tahsilat (Adisyonlar::tamOde);
     * "Geri Al" (false) → adisyonun TÜM tahsilatları silinir (Adisyonlar::odemeGeriAl).
     */
    public function studyoOdeme(int $adisyonId, int $salonId, bool $alindi, ?int $olusturanId): array
    {
        $a = DB::table('adisyonlar')->where('id', $adisyonId)->where('salon_id', $salonId)->first(['id', 'user_id', 'salon_id']);
        if (! $a) {
            throw new BulunamadiException('Adisyon bulunamadı');
        }
        $odendiKol = Schema::hasColumn('adisyonlar', 'odendi');
        $tarihKol = Schema::hasColumn('adisyonlar', 'odendi_tarihi');

        DB::transaction(function () use ($a, $alindi, $olusturanId, $odendiKol, $tarihKol) {
            DB::table('adisyonlar')->where('id', $a->id)->lockForUpdate()->first(['id']);

            if ($alindi) {
                $this->tamOde($a, $olusturanId);
            } else {
                $ids = DB::table('tahsilatlar')->where('adisyon_id', $a->id)->pluck('id')->all();
                if ($ids) {
                    DB::table('tahsilat_hizmetler')->whereIn('tahsilat_id', $ids)->delete();
                    DB::table('tahsilat_urunler')->whereIn('tahsilat_id', $ids)->delete();
                    DB::table('tahsilat_paketler')->whereIn('tahsilat_id', $ids)->delete();
                    DB::table('tahsilatlar')->where('adisyon_id', $a->id)->delete();
                }
            }

            $guncelle = [];
            if ($odendiKol) {
                $guncelle['odendi'] = $alindi ? 1 : 0;
            }
            if ($tarihKol) {
                $guncelle['odendi_tarihi'] = $alindi ? now() : null;
            }
            if ($guncelle) {
                DB::table('adisyonlar')->where('id', $a->id)->update($guncelle);
            }
        });

        return ['adisyon_id' => $adisyonId, 'odendi' => $alindi ? 1 : 0];
    }

    /** Eski Adisyonlar::tamOde birebir: her kalemin kalanı (fiyat−ödenen−indirim>0) tek tahsilatta. */
    private function tamOde(object $a, ?int $olusturanId): void
    {
        $kalemler = [];
        $toplam = 0.0;
        foreach ([
            ['adisyon_hizmetler', 'tahsilat_hizmetler', 'adisyon_hizmet_id'],
            ['adisyon_urunler', 'tahsilat_urunler', 'adisyon_urun_id'],
            ['adisyon_paketler', 'tahsilat_paketler', 'adisyon_paket_id'],
        ] as [$kalemTablo, $tahTablo, $fk]) {
            foreach (DB::table($kalemTablo)->where('adisyon_id', $a->id)->get(['id', 'fiyat', 'indirim_tutari']) as $k) {
                $odenen = (float) DB::table($tahTablo)->where($fk, $k->id)->sum('tutar');
                $kalan = (float) $k->fiyat - $odenen - (float) ($k->indirim_tutari ?? 0);
                if ($kalan > 0) {
                    $kalemler[] = [$tahTablo, $fk, $k->id, $kalan];
                    $toplam += $kalan;
                }
            }
        }

        $yontem = DB::table('odeme_yontemleri')->orderBy('id')->value('id') ?: 1;
        $satir = [
            'adisyon_id'       => $a->id,
            'user_id'          => $a->user_id,
            'salon_id'         => $a->salon_id,
            'odeme_tarihi'     => now()->toDateString(),
            'odeme_yontemi_id' => $yontem,
            'tutar'            => $toplam,
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
        if ($olusturanId) {
            $satir['olusturan_id'] = $olusturanId;
        }
        if (Schema::hasColumn('tahsilatlar', 'yapilan_odeme')) {
            $satir['yapilan_odeme'] = $toplam;
        }
        $tahsilatId = DB::table('tahsilatlar')->insertGetId($satir);

        foreach ($kalemler as [$tahTablo, $fk, $kalemId, $tutar]) {
            DB::table($tahTablo)->insert([
                $fk           => $kalemId,
                'tahsilat_id' => $tahsilatId,
                'tutar'       => $tutar,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }
}
