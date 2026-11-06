<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Exceptions\BulunamadiException;
use App\Services\SistemYonetim\Denetim;
use App\Support\ListeIstegi;
use App\Support\SorguSayfasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asistan bedava kalip kutuphanesi + AI'ya dusen sorular (eski AsistanEgitimController). Kalip cache'i
 * ('asistan_kalip_liste_v1') eski monolitte 10 dk: buradan silinemez, degisiklik en gec bu surede yansir.
 */
class AsistanEgitimServisi
{
    private const KALIP = 'asistan_kalip';

    private const COZULMEYEN = 'asistan_cozulmeyen';

    public const YANSIMA_NOTU = ' Asistana yansıması en geç 10 dakika sürer.';

    public const KALIP_SIRALAMA = ['kullanim' => 'kullanim_sayisi', 'id' => 'id'];

    public const COZULMEYEN_SIRALAMA = ['adet' => 'adet', 'son' => 'son_tarih'];

    private const PDF_KATEGORI = 'pdf';

    public function acilis(): array
    {
        if (! Schema::hasTable(self::KALIP)) {
            return ['tablo_yok' => true, 'ozet' => ['kalip' => 0, 'bedava' => 0, 'bekleyen' => 0]];
        }

        return ['tablo_yok' => false, 'ozet' => [
            'kalip'    => DB::table(self::KALIP)->count(),
            'bedava'   => (int) DB::table(self::KALIP)->sum('kullanim_sayisi'),
            'bekleyen' => Schema::hasTable(self::COZULMEYEN) ? DB::table(self::COZULMEYEN)->count() : 0,
        ]];
    }

    public function kaliplar(array $l): array
    {
        if (! Schema::hasTable(self::KALIP)) {
            return ['data' => []] + ListeIstegi::meta($l['page'], $l['per_page'], 0);
        }

        return SorguSayfasi::uret(DB::table(self::KALIP), $l, ['tetikleyiciler', 'cevap', 'kategori'], self::KALIP_SIRALAMA, fn ($k) => [
            'id'                  => (int) $k->id,
            'tetikleyiciler'      => $k->tetikleyiciler,
            'tetikleyici_listesi' => array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) $k->tetikleyiciler) ?: []), fn ($t) => $t !== '')),
            'cevap'               => $k->cevap,
            'kategori'            => $k->kategori,
            'aktif'               => (bool) $k->aktif,
            'kullanim_sayisi'     => (int) $k->kullanim_sayisi,
        ]);
    }

    public function cozulmeyenler(array $l): array
    {
        if (! Schema::hasTable(self::COZULMEYEN)) {
            return ['data' => []] + ListeIstegi::meta($l['page'], $l['per_page'], 0);
        }

        return SorguSayfasi::uret(DB::table(self::COZULMEYEN), $l, ['ham', 'soru_norm'], self::COZULMEYEN_SIRALAMA, fn ($c) => [
            'id'        => (int) $c->id,
            'soru'      => $c->ham ?: $c->soru_norm,
            'adet'      => (int) $c->adet,
            'son_tarih' => $c->son_tarih,
        ], 'son_tarih');
    }

    /** Yeni kalip. Cozulmeyen bir sorudan cevrildiyse o soru listeden duser. */
    public function kalipEkle(array $g): int
    {
        return DB::transaction(function () use ($g) {
            $simdi = date('Y-m-d H:i:s');
            $id = DB::table(self::KALIP)->insertGetId($this->kalipAlanlari($g) + ['kullanim_sayisi' => 0, 'created_at' => $simdi, 'updated_at' => $simdi]);
            if (! empty($g['cozulmeyen_id'])) {
                DB::table(self::COZULMEYEN)->where('id', (int) $g['cozulmeyen_id'])->delete();
            }
            Denetim::yaz('asistan_kalip_ekle', 'asistan_kalip', $id, $this->etiket($g['tetikleyiciler']), 'Kalıp eklendi');

            return $id;
        });
    }

    public function kalipGuncelle(int $id, array $g): void
    {
        if (! DB::table(self::KALIP)->where('id', $id)->exists()) {
            throw new BulunamadiException('Kalıp bulunamadı');
        }
        DB::table(self::KALIP)->where('id', $id)->update($this->kalipAlanlari($g) + ['updated_at' => date('Y-m-d H:i:s')]);
        Denetim::yaz('asistan_kalip_guncelle', 'asistan_kalip', $id, $this->etiket($g['tetikleyiciler']), 'Kalıp güncellendi');
    }

    public function kalipSil(int $id): void
    {
        $k = DB::table(self::KALIP)->where('id', $id)->first(['id', 'tetikleyiciler']);
        if (! $k) {
            throw new BulunamadiException('Kalıp bulunamadı');
        }
        DB::table(self::KALIP)->where('id', $id)->delete();
        Denetim::yaz('asistan_kalip_sil', 'asistan_kalip', $id, $this->etiket($k->tetikleyiciler), 'Kalıp silindi');
    }

    public function cozulmeyenSil(int $id): void
    {
        if (! DB::table(self::COZULMEYEN)->where('id', $id)->delete()) {
            throw new BulunamadiException('Soru bulunamadı');
        }
        Denetim::yaz('asistan_cozulmeyen_sil', 'asistan_cozulmeyen', $id, null, 'Soru listeden kaldırıldı');
    }

    /** PDF onizlemesinden istemcinin secip duzenledigi maddeler. Bos madde atlanir. @return int eklenen */
    public function topluEkle(array $maddeler, ?string $dosya): int
    {
        $simdi = date('Y-m-d H:i:s');
        $satirlar = [];
        foreach ($maddeler as $m) {
            $tetik = trim((string) ($m['tetikleyiciler'] ?? ''));
            $cevap = trim((string) ($m['cevap'] ?? ''));
            if ($tetik === '' || $cevap === '') {
                continue;
            }
            $satirlar[] = [
                'tetikleyiciler' => $tetik, 'cevap' => $cevap,
                'kategori' => trim((string) ($m['kategori'] ?? '')) ?: self::PDF_KATEGORI,
                'aktif' => 1, 'kullanim_sayisi' => 0, 'created_at' => $simdi, 'updated_at' => $simdi,
            ];
        }
        if ($satirlar) {
            DB::table(self::KALIP)->insert($satirlar);
            Denetim::yaz('asistan_pdf_kaydet', 'asistan_kalip', null, $dosya, count($satirlar).' kalıp eklendi', ['adet' => count($satirlar)]);
        }

        return count($satirlar);
    }

    private function kalipAlanlari(array $g): array
    {
        return [
            'tetikleyiciler' => trim($g['tetikleyiciler']),
            'cevap'          => trim($g['cevap']),
            'kategori'       => ($g['kategori'] ?? '') ?: null,
            'aktif'          => ($g['aktif'] ?? true) ? 1 : 0,
        ];
    }

    private function etiket(string $tetikleyiciler): string
    {
        return mb_substr(trim($tetikleyiciler), 0, 120);
    }
}
