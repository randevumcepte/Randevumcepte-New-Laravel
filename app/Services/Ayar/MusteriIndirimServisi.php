<?php

namespace App\Services\Ayar;

use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Musteri kategorisi indirimleri (Sadik / Aktif musteri yuzdesi) — eski
 * StoreAdminController@musteriindirim_kaydet ve v1 musteriindirim_kaydet'in TEK karsiligi.
 * Kapali = yuzde 0 (eski sema). Satis tarafinda uygulanmasi MusteriOkumaServisi'nde.
 */
class MusteriIndirimServisi
{
    private const KOLON = ['sadik' => 'sadik_musteri_indirim_yuzde', 'aktif' => 'aktif_musteri_indirim_yuzde'];

    /** @return array{sadik:array{acik:bool,yuzde:float}, aktif:array{acik:bool,yuzde:float}} */
    public function getir(int $salonId): array
    {
        $s = DB::table('salonlar')->where('id', $salonId)->first(array_values(self::KOLON));
        if (! $s) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        $veri = [];
        foreach (self::KOLON as $tur => $kolon) {
            $yuzde = (float) ($s->{$kolon} ?? 0);
            $veri[$tur] = ['acik' => $yuzde > 0, 'yuzde' => $yuzde];
        }

        return $veri;
    }

    /** @param  array{sadik:array{acik:bool,yuzde:float}, aktif:array{acik:bool,yuzde:float}}  $g */
    public function kaydet(int $salonId, int $yapanId, array $g): array
    {
        $eski = $this->getir($salonId);
        $guncelle = [];
        foreach (self::KOLON as $tur => $kolon) {
            $guncelle[$kolon] = ! empty($g[$tur]['acik']) ? (float) ($g[$tur]['yuzde'] ?? 0) : 0;
        }
        DB::table('salonlar')->where('id', $salonId)->update($guncelle);

        AktiviteLog::yaz($salonId, $yapanId, 'musteri_indirim_kurali_kaydet', 'salon', $salonId,
            'Sadık %'.$guncelle[self::KOLON['sadik']].' / Aktif %'.$guncelle[self::KOLON['aktif']],
            'Müşteri kategorisi indirim kuralları güncellendi',
            ['eski' => ['sadik' => $eski['sadik']['yuzde'], 'aktif' => $eski['aktif']['yuzde']],
             'yeni' => ['sadik' => $guncelle[self::KOLON['sadik']], 'aktif' => $guncelle[self::KOLON['aktif']]]]);

        return $this->getir($salonId);
    }
}
