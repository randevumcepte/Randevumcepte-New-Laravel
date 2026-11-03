<?php

namespace App\Services\Kasa;

use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Masraf;
use App\Models\MasrafKategorisi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Masraf / personel gideri — eski masrafekleduzenle, masraf_detay, masrafSil (web) ve
 * mobil masrafekleduzenle / masrafsil / masrafkategorileri karsiligi. Kasa Raporu'nun
 * "Yeni Masraf" ve satir duzenle/sil islemleri; ileride Masraflar ekrani da kullanir.
 *
 * Duzeltilenler: detay / duzenle / sil SALON KAPSAMLI (eskiden baska salonun masrafi
 * okunup silinebiliyordu), tutar sayi olarak gelir (TR bicim ayristirma yok).
 */
class MasrafServisi
{
    public const PERSONEL_GIDERI_KATEGORI = 'Personel Gideri';

    /** Form secenekleri — kategoriler (global tablo) + odeme yontemleri. */
    public function formVerisi(): array
    {
        return [
            'kategoriler'      => DB::table('masraf_kategorileri')->orderBy('kategori')->get(['id', 'kategori'])->all(),
            'odeme_yontemleri' => DB::table('odeme_yontemleri')->orderBy('id')->get(['id', 'odeme_yontemi'])->all(),
        ];
    }

    public function getir(int $salonId, int $id): array
    {
        $m = $this->bul($salonId, $id);

        return [
            'id'               => (int) $m->id,
            'tarih'            => substr((string) $m->tarih, 0, 10),
            'tutar'            => round((float) $m->tutar, 2),
            'aciklama'         => $m->aciklama,
            'notlar'           => $m->notlar,
            'masraf_kategori_id' => $m->masraf_kategori_id ? (int) $m->masraf_kategori_id : null,
            'odeme_yontemi_id' => $m->odeme_yontemi_id ? (int) $m->odeme_yontemi_id : null,
            'harcayan_id'      => $m->harcayan_id ? (int) $m->harcayan_id : null,
            'harcayan_adi'     => $m->harcayan_id ? DB::table('salon_personelleri')->where('id', $m->harcayan_id)->value('personel_adi') : null,
            'personel_gideri'  => (bool) $m->personel_gideri,
            'personel_odemesi' => (bool) $m->personel_maas_odemesi_id,
        ];
    }

    /**
     * Ekle ($id null) / guncelle. Personel giderinde kategori bossa "Personel Gideri"
     * kategorisi, odeme yontemi bossa Nakit (eskiyle ayni: masraflar listesi bu iki
     * alani INNER JOIN eder, bos kalirsa satir gorunmez).
     *
     * @param  array{tarih:string, tutar:float, personel_gideri?:bool, masraf_kategori_id?:?int, odeme_yontemi_id?:?int, harcayan_id?:?int, aciklama?:?string, notlar?:?string}  $v
     */
    public function kaydet(int $salonId, int $yetkiliId, array $v, ?int $id = null): array
    {
        return DB::transaction(function () use ($salonId, $yetkiliId, $v, $id) {
            $m = $id ? $this->bul($salonId, $id) : new Masraf(['salon_id' => $salonId]);
            if ($m->personel_maas_odemesi_id) {
                // Tutar personel_maas_odemeleri ile senkron kalmali → odeme ekranindan duzenlenir.
                throw new GecersizIslemException('Personel ödemesi kaydı Prim & Hak Ediş ekranından düzenlenir.');
            }
            $gideri = ! empty($v['personel_gideri']);
            $kategori = $v['masraf_kategori_id'] ?? null;
            $odeme = $v['odeme_yontemi_id'] ?? null;
            if ($gideri && ! $kategori) {
                $kategori = MasrafKategorisi::firstOrCreate(['kategori' => self::PERSONEL_GIDERI_KATEGORI])->id;
            }
            if ($gideri && ! $odeme) {
                $odeme = DB::table('odeme_yontemleri')->where('odeme_yontemi', 'like', '%Nakit%')->value('id')
                    ?: DB::table('odeme_yontemleri')->orderBy('id')->value('id');
            }

            $m->fill([
                'tarih'              => $v['tarih'],
                'tutar'              => round((float) $v['tutar'], 2),
                'masraf_kategori_id' => $kategori,
                'odeme_yontemi_id'   => $odeme,
                'harcayan_id'        => $v['harcayan_id'] ?? null,
                'personel_gideri'    => $gideri ? 1 : 0,
                'aciklama'           => $v['aciklama'] ?? null,
            ]);
            if (array_key_exists('notlar', $v)) {
                $m->notlar = $v['notlar'];
            }
            $m->save();

            $ad = $gideri ? 'Personel gideri' : 'Masraf';
            AktiviteLog::yaz($salonId, $yetkiliId, $id ? 'masraf_guncelle' : 'masraf_ekle', 'masraf', (int) $m->id,
                ($m->aciklama ?: 'Masraf').' — '.number_format((float) $m->tutar, 2, ',', '.').' ₺',
                $id ? 'Masraf güncellendi' : 'Yeni masraf kaydedildi',
                ['tarih' => $m->tarih, 'kategori' => $m->masraf_kategori_id, 'odeme' => $m->odeme_yontemi_id]);

            return ['masraf_id' => (int) $m->id, 'mesaj' => $ad.' başarıyla '.($id ? 'güncellendi' : 'kaydedildi')];
        });
    }

    /** Siler; bagli personel maas/prim odemesi varsa o da silinir (eskiyle ayni). */
    public function sil(int $salonId, int $yetkiliId, int $id): void
    {
        DB::transaction(function () use ($salonId, $yetkiliId, $id) {
            $m = $this->bul($salonId, $id);
            AktiviteLog::yaz($salonId, $yetkiliId, 'masraf_sil', 'masraf', (int) $m->id,
                ($m->aciklama ?: 'Masraf').' — '.number_format((float) $m->tutar, 2, ',', '.').' ₺', 'Masraf silindi',
                ['tarih' => $m->tarih, 'tutar' => $m->tutar, 'personel_odeme_id' => $m->personel_maas_odemesi_id]);
            if ($m->personel_maas_odemesi_id) {
                DB::table('personel_maas_odemeleri')->where('id', $m->personel_maas_odemesi_id)->where('salon_id', $salonId)->delete();
            }
            $m->delete();
        });
    }

    /** Kaydin salonu (kapsam icin) — yoksa null (controller 404 verir). */
    public function salonId(int $id): ?int
    {
        $s = DB::table('masraflar')->where('id', $id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    private function bul(int $salonId, int $id): Masraf
    {
        return Masraf::where('id', $id)->where('salon_id', $salonId)->first()
            ?? throw new BulunamadiException('Masraf bulunamadı');
    }
}
