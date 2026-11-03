<?php

namespace App\Services\Randevu;

use App\Exceptions\GecersizIslemException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Randevu servislerinin ORTAK sorgu/dogrulama yardimcilari — TEK KAYNAK
 * (RandevuKayit / RandevuTakvimYaz / TelafiRandevu / RandevuForm servislerinde
 * kopyalanmaz).
 */
final class RandevuOrtak
{
    /** Kalem tipi → tablo + Turkce ad (kaynak dogrulama mesajlari icin). */
    private const KAYNAK = [
        'personel_id' => ['salon_personelleri', 'personel'],
        'cihaz_id'    => ['cihazlar', 'cihaz'],
        'oda_id'      => ['odalar', 'oda'],
    ];

    /** Kapsam denetimi icin: randevu kaleminin (randevu_hizmetler) salonu; yoksa null. */
    public static function kalemSalonId(int $rhId): ?int
    {
        $v = DB::table('randevu_hizmetler as rh')->join('randevular as r', 'r.id', '=', 'rh.randevu_id')
            ->where('rh.id', $rhId)->value('r.salon_id');

        return $v !== null ? (int) $v : null;
    }

    /**
     * Secilen kaynaklar (personel_id / cihaz_id / oda_id → id) AYNI salona ait mi;
     * degilse 422. Bos (null/0) kaynaklar atlanir.
     *
     * @param  array<string,int|null>  $kaynak
     *
     * @throws GecersizIslemException
     */
    public static function kaynaklarSalonaAit(int $salonId, array $kaynak): void
    {
        foreach (array_intersect_key($kaynak, self::KAYNAK) as $kolon => $id) {
            if ($id && ! DB::table(self::KAYNAK[$kolon][0])->where('id', $id)->where('salon_id', $salonId)->exists()) {
                throw new GecersizIslemException('Seçilen '.self::KAYNAK[$kolon][1].' bu işletmeye ait değil.');
            }
        }
    }

    /**
     * Satis satiri "otomatik randevu olusturuldu" ile kapanmamis (NULL ya da != 1).
     * Seans adayi secimi ve musteri paket listesinde ayni kural (eski sistemle ayni).
     */
    public static function otomatikRandevuDegil(Builder $q, string $tablo): Builder
    {
        return $q->where(fn ($w) => $w->whereNull($tablo.'.otomatik_randevu_olusturuldu')
            ->orWhere($tablo.'.otomatik_randevu_olusturuldu', '!=', 1));
    }
}
