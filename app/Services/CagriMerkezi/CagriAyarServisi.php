<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Çağrı Merkezi AYARLARI (yönetici) — görüşme scriptleri + sonuç kategori ağacı tanımlama.
 * Eski /isletmeyonetim/cagri-ayarlari + cagri_script_* / cagri_kategori_* karşılığı. Agent tarafı
 * aynı veriyi CagriReferansServisi ile (yalnız aktifler) okur.
 */
class CagriAyarServisi
{
    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private CagriReferansServisi $referans,
    ) {}

    /** Yönetici görünümü: TÜM scriptler (pasifler dahil) + kategori ağacı. */
    public function getir(CagriMerkeziErisim $e): array
    {
        $this->erisim->yoneticiZorunlu($e);

        return [
            'scriptler'   => DB::table('cagri_scriptleri')->where('salon_id', $e->salonId)->orderByDesc('id')->get(['id', 'baslik', 'icerik', 'aktif'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'baslik' => (string) $s->baslik, 'icerik' => (string) $s->icerik, 'aktif' => (int) $s->aktif === 1])->all(),
            'kategoriler' => $this->referans->kategoriler($e->salonId),
        ];
    }

    /** @param array{baslik:string, icerik:?string, aktif:?bool} $v */
    public function scriptKaydet(CagriMerkeziErisim $e, ?int $id, array $v): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $kolon = ['baslik' => mb_substr(trim($v['baslik']), 0, 190), 'icerik' => (string) ($v['icerik'] ?? ''), 'aktif' => ($v['aktif'] ?? true) ? 1 : 0, 'updated_at' => now()];
        if ($id) {
            $this->scriptBul($e, $id);
            DB::table('cagri_scriptleri')->where('id', $id)->update($kolon);
        } else {
            $id = (int) DB::table('cagri_scriptleri')->insertGetId($kolon + ['salon_id' => $e->salonId, 'created_at' => now()]);
        }
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_script_kaydet', 'cagri_script', $id, $kolon['baslik'], 'Görüşme scripti kaydedildi');

        return ['id' => $id];
    }

    public function scriptSil(CagriMerkeziErisim $e, int $id): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $s = $this->scriptBul($e, $id);
        DB::table('cagri_scriptleri')->where('id', $id)->delete();
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_script_sil', 'cagri_script', $id, (string) $s->baslik, 'Görüşme scripti silindi');

        return ['id' => $id];
    }

    /** Kategori ekle / yeniden adlandır. ust_id yalnız yeni kayıtta; bu salonun ANA kategorisi olmalı. */
    public function kategoriKaydet(CagriMerkeziErisim $e, ?int $id, string $ad, ?int $ustId): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $ad = mb_substr(trim($ad), 0, 190);
        if ($id) {
            $this->kategoriBul($e, $id);
            DB::table('cagri_kategorileri')->where('id', $id)->update(['ad' => $ad, 'updated_at' => now()]);
        } else {
            if ($ustId && ! DB::table('cagri_kategorileri')->where('id', $ustId)->where('salon_id', $e->salonId)->whereNull('ust_id')->exists()) {
                throw new GecersizIslemException('Üst kategori bulunamadı (alt kategori yalnız ana kategoriye eklenir).');
            }
            $sira = (int) DB::table('cagri_kategorileri')->where('salon_id', $e->salonId)->where('ust_id', $ustId)->max('sira') + 1;
            $id = (int) DB::table('cagri_kategorileri')->insertGetId([
                'salon_id' => $e->salonId, 'ust_id' => $ustId ?: null, 'ad' => $ad, 'sira' => $sira, 'aktif' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_kategori_kaydet', 'cagri_kategori', $id, $ad, 'Sonuç kategorisi kaydedildi');

        return ['id' => $id];
    }

    /** Ana kategori silinirse alt kategorileri de gider (görüşme notlarındaki kategori_id kalır, etiket boş görünür). */
    public function kategoriSil(CagriMerkeziErisim $e, int $id): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $k = $this->kategoriBul($e, $id);
        DB::transaction(function () use ($e, $id) {
            DB::table('cagri_kategorileri')->where('salon_id', $e->salonId)->where('ust_id', $id)->delete();
            DB::table('cagri_kategorileri')->where('id', $id)->delete();
        });
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_kategori_sil', 'cagri_kategori', $id, (string) $k->ad, 'Sonuç kategorisi silindi');

        return ['id' => $id];
    }

    private function scriptBul(CagriMerkeziErisim $e, int $id): object
    {
        return DB::table('cagri_scriptleri')->where('id', $id)->where('salon_id', $e->salonId)->first() ?? throw new BulunamadiException('Script bulunamadı');
    }

    private function kategoriBul(CagriMerkeziErisim $e, int $id): object
    {
        return DB::table('cagri_kategorileri')->where('id', $id)->where('salon_id', $e->salonId)->first() ?? throw new BulunamadiException('Kategori bulunamadı');
    }
}
