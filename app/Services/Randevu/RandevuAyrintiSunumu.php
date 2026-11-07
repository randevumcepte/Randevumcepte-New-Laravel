<?php

namespace App\Services\Randevu;

use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Services\Yetki\YetkiServisi;
use App\Support\KvkkMaske;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Randevu ve kalemlerinin ayrintili satiri: duzenleme, detay ve tahsilat ekranlarinin okudugu alanlar
 * (takvim ve randevu listesi `ayrinti=1` ortak). Yetki: musteri notu musteri.not_yaz, telefon
 * musteri.telefon_gor ister; saglik, maas ve prim alanlari donmez.
 */
class RandevuAyrintiSunumu
{
    public const PERSONEL_ALANLARI = ['id', 'personel_adi', 'salon_id', 'unvan', 'cinsiyet', 'renk', 'takvim_sirasi', 'takvimde_gorunsun', 'aktif'];

    private const MUSTERI_ALANLARI = ['id', 'name', 'cep_telefon', 'email', 'cinsiyet', 'dogum_tarihi', 'il_id'];

    public function __construct(private YetkiServisi $yetki) {}

    /** @return array{not:bool,tel:bool} */
    public function yetkiler(int $yetkiliId, int $salonId): array
    {
        return [
            'not' => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.not_yaz'),
            'tel' => $this->yetki->yetkiliYetkiVar($yetkiliId, $salonId, 'musteri.telefon_gor'),
        ];
    }

    /**
     * Liste satirlarina `ayrinti` ekler; mevcut alanlar degismez.
     *
     * @param  array<int,array<string,mixed>>  $satirlar  her biri `id` tasir
     */
    public function listeyeEkle(array $satirlar, int $yetkiliId, int $salonId): array
    {
        $randevular = Randevular::with('hizmetler')->whereIn('id', array_column($satirlar, 'id'))->get();
        $ayrinti = array_column($this->satirlar($randevular, $this->yetkiler($yetkiliId, $salonId)), null, 'id');

        return array_map(fn ($s) => $s + ['ayrinti' => $ayrinti[$s['id']] ?? null], $satirlar);
    }

    /**
     * @param  Collection<int,Randevular>  $randevular  `hizmetler` yuklu
     * @param  array{not:bool,tel:bool}  $yetki
     * @return array<int,array<string,mixed>>
     */
    public function satirlar(Collection $randevular, array $yetki): array
    {
        $kalemler = $randevular->pluck('hizmetler')->flatten(1);
        $hizmet = $this->kayitlar('hizmetler', $kalemler->pluck('hizmet_id'));
        $personel = $this->kayitlar('salon_personelleri', $kalemler->pluck('personel_id'), self::PERSONEL_ALANLARI);
        $cihaz = $this->kayitlar('cihazlar', $kalemler->pluck('cihaz_id'));
        $oda = $this->kayitlar('odalar', $kalemler->pluck('oda_id'));
        $musteri = $this->kayitlar('users', $randevular->pluck('user_id'), self::MUSTERI_ALANLARI);
        $olusturanMusteri = $this->kayitlar('users', $randevular->pluck('olusturan_user_id'), ['id', 'name']);
        $olusturan = $this->kayitlar('isletmeyetkilileri', $randevular->pluck('olusturan_personel_id'), ['id', 'name']);
        $salon = $this->kayitlar('salonlar', $randevular->pluck('salon_id'), ['id', 'salon_adi']);

        return $randevular->map(function (Randevular $r) use ($hizmet, $personel, $cihaz, $oda, $musteri, $olusturan, $olusturanMusteri, $salon, $yetki) {
            $satir = $r->attributesToArray();
            if (! $yetki['not']) {
                unset($satir['notlar'], $satir['musteri_notu']);
            }
            if (! $yetki['tel'] && ! empty($satir['telefon'])) {
                $satir['telefon'] = KvkkMaske::telefon($satir['telefon']);
            }

            $m = (array) ($musteri[$r->user_id] ?? []);
            if ($m && ! $yetki['tel']) {
                $m['cep_telefon'] = KvkkMaske::telefon($m['cep_telefon']);
            }

            return array_merge($satir, [
                'users'              => $m ?: ['id' => $r->user_id, 'name' => null],
                'olusturan_personel' => $olusturan[$r->olusturan_personel_id] ?? null,
                'olusturan_musteri'  => $olusturanMusteri[$r->olusturan_user_id] ?? null,
                'salonlar'           => $salon[$r->salon_id] ?? null,
                'hizmetler'          => $r->hizmetler->map(fn (RandevuHizmetler $k) => $k->attributesToArray() + [
                    'hizmetler'   => $hizmet[$k->hizmet_id] ?? null,
                    'personeller' => $personel[$k->personel_id] ?? null,
                    'cihaz'       => $cihaz[$k->cihaz_id] ?? null,
                    'oda'         => $oda[$k->oda_id] ?? null,
                ])->all(),
            ]);
        })->all();
    }

    private function kayitlar(string $tablo, Collection $idler, array $alanlar = ['*']): Collection
    {
        return DB::table($tablo)->whereIn('id', $idler->filter()->unique())->get($alanlar)->keyBy('id');
    }
}
