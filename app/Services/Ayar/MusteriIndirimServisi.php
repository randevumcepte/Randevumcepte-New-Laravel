<?php

namespace App\Services\Ayar;

use App\Domain\Musteri\Segment;
use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Musteri kategorisi indirimleri (Sadik / Aktif musteri yuzdesi) — eski
 * StoreAdminController@musteriindirim_kaydet ve v1 musteriindirim_kaydet'in TEK karsiligi.
 * Kapali = yuzde 0 (eski sema). Tahsilatta uygulanan yuzde: adisyonIcin() (web + Flutter tek kural).
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

    /**
     * Bu adisyonun tahsilatinda gecerli musteri indirimi (eski tahsilatbilgigetir): musterinin bu salondaki
     * BU ADISYONDAN ONCEKI tahsilatlari (adisyonsuz ya da daha eski adisyon) sayilir; segment TEK kuraldan
     * (Segment: 1-2 aktif, 3+ sadik). Pasif musteri / kapali kural → null. Indirim, tahsil edilen BRUT
     * tutarin yuzdesidir (eski: her kalem tutarina %); parcali odemelerde de toplam = brut x yuzde.
     *
     * @return array{tur:string, etiket:string, yuzde:float, tahsilat_sayisi:int}|null
     */
    public function adisyonIcin(int $salonId, int $userId, int $adisyonId): ?array
    {
        if (! $userId) {
            return null;
        }
        $sayi = DB::table('tahsilatlar')->where('salon_id', $salonId)->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('adisyon_id')->orWhere('adisyon_id', '<', $adisyonId))
            ->count();
        $segment = Segment::ozet($sayi);
        $tur = match ($segment['kod']) {
            Segment::SADIK => 'sadik',
            Segment::AKTIF => 'aktif',
            default        => null,
        };
        if (! $tur) {
            return null;
        }
        $yuzde = $this->getir($salonId)[$tur]['yuzde'];

        return $yuzde > 0 ? ['tur' => $tur, 'etiket' => $segment['etiket'].' müşteri', 'yuzde' => $yuzde, 'tahsilat_sayisi' => $sayi] : null;
    }

    /** Brut tutardaki musteri indirimi payi (TL, kurus yuvarlama) — yetki kapisi ve istemci ayni formul. */
    public static function pay(float $brut, float $yuzde): float
    {
        return round(max(0, $brut) * $yuzde / 100, 2);
    }
}
