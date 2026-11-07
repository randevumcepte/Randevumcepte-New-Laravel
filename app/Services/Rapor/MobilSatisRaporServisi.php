<?php

namespace App\Services\Rapor;

use App\Domain\Rapor\MobilSatisRaporu;
use App\Domain\Satis\KalemTuru;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Support\Facades\DB;

/**
 * Mobil satis raporlari (mobil bayragi ile) — eski ApiController hizmet/urun/paket/personelRaporlari ve
 * *MusteriListesiGetir kapsam ve filtreleri BIREBIR. Web bu servisi kullanmaz (SatisRaporServisi).
 *
 * Tarih kurali eskisiyle ayni: hizmet ve paket raporunun seansli kismi bos tarihte created_at'e duser, digerleri
 * adisyonlar.tarih'e bakar. Tahsilat toplami kalemin tum tahsilatidir, donemden bagimsizdir.
 */
class MobilSatisRaporServisi
{

    public function hizmetRaporu(int $salonId, string $t1, string $t2, ?int $personelId): array
    {
        $kalemler = array_filter(
            $this->kalemler(KalemTuru::Hizmet, $salonId, $t1, $t2, $personelId, true),
            fn ($k) => ! MobilSatisRaporu::seansliMi($k),
        );

        return MobilSatisRaporu::satirlar(array_values($kalemler), 'hizmet_adi', 'hizmet_id', $this->kazanc(KalemTuru::Hizmet));
    }

    public function urunRaporu(int $salonId, string $t1, string $t2, ?int $personelId): array
    {
        return MobilSatisRaporu::satirlar($this->kalemler(KalemTuru::Urun, $salonId, $t1, $t2, $personelId), 'urun_adi', 'urun_id', $this->kazanc(KalemTuru::Urun));
    }

    /** Gercek paketler + seansli hizmetler ("(Seansli)" ekiyle, paket_id alani hizmet_id tasir). */
    public function paketRaporu(int $salonId, string $t1, string $t2, ?int $personelId): array
    {
        $seansli = array_filter(
            $this->kalemler(KalemTuru::Hizmet, $salonId, $t1, $t2, $personelId, true),
            fn ($k) => MobilSatisRaporu::seansliMi($k),
        );

        return array_merge(
            MobilSatisRaporu::satirlar($this->kalemler(KalemTuru::Paket, $salonId, $t1, $t2, $personelId), 'paket_adi', 'paket_id', $this->kazanc(KalemTuru::Paket)),
            MobilSatisRaporu::satirlar(array_values($seansli), 'paket_adi', 'paket_id', $this->kazanc(KalemTuru::Hizmet), ' (Seansli)'),
        );
    }

    public function personelRaporu(int $salonId, string $t1, string $t2): array
    {
        $personelli = function (KalemTuru $tur) use ($salonId, $t1, $t2) {
            $kalemler = $this->kalemler($tur, $salonId, $t1, $t2, null);
            $personeller = DB::table('salon_personelleri')->whereIn('id', array_filter(array_column($kalemler, 'personel_id')))
                ->get(['id', 'personel_adi', 'aktif', 'hizmet_prim_yuzde', 'urun_prim_yuzde', 'paket_prim_yuzde'])->keyBy('id');

            return array_map(function ($k) use ($personeller) {
                $p = $personeller[$k['personel_id']] ?? null;

                return $k + ['personel' => $p ? (array) $p : null];
            }, $kalemler);
        };

        return MobilSatisRaporu::personelRaporu(
            $personelli(KalemTuru::Hizmet), $personelli(KalemTuru::Urun), $personelli(KalemTuru::Paket),
            fn (string $tur, array $ids) => $this->kazanc(KalemTuru::from($tur))($ids),
        );
    }

    /**
     * Adisyon basina bir musteri satiri. $olusturanId: adisyonu acan personel (eski personelId filtresi).
     * Telefon yetkisi yoksa ekrandakiyle ayni maskeyle gider.
     */
    public function musteriler(string $tur, int $salonId, int $kalemId, string $t1, string $t2, ?int $olusturanId, bool $telefonGor): array
    {
        $var = fn (string $tablo, string $fk, ?callable $ek = null) => DB::table($tablo.' as k')->whereColumn('k.adisyon_id', 'a.id')
            ->where('k.'.$fk, $kalemId)->when($ek, $ek);

        $satirlar = DB::table('adisyonlar as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.salon_id', $salonId)
            ->whereBetween('a.tarih', [$t1, $t2])
            ->when($olusturanId, fn ($q) => $q->where('a.olusturan_id', $olusturanId))
            ->where(function ($q) use ($tur, $var) {
                match ($tur) {
                    'hizmet' => $q->whereExists($var('adisyon_hizmetler', 'hizmet_id', fn ($s) => $s->where(fn ($w) => $w
                        ->whereNotNull('k.randevu_id')->orWhereNull('k.seans_sayisi')->orWhere('k.seans_sayisi', '<=', 0)))),
                    'urun'   => $q->whereExists($var('adisyon_urunler', 'urun_id')),
                    'paket'  => $q->whereExists($var('adisyon_paketler', 'paket_id'))
                        ->orWhereExists($var('adisyon_hizmetler', 'hizmet_id', fn ($s) => $s->whereNull('k.randevu_id')->where('k.seans_sayisi', '>', 0))),
                };
            })
            ->orderBy('a.id')
            ->get(['u.id', 'u.name', 'u.cep_telefon', 'u.email']);

        return $satirlar->map(fn ($u) => [
            'id'          => $u->id,
            'name'        => $u->name ?? 'Müşteri',
            'cep_telefon' => $telefonGor || $u->cep_telefon === null ? $u->cep_telefon : YetkiSabitleri::telefonMaskele($u->cep_telefon),
            'email'       => $u->email,
        ])->all();
    }

    /** Kalem satirlari, adisyon ve kalem sirasiyla. @return array<int,array> */
    private function kalemler(KalemTuru $tur, int $salonId, string $t1, string $t2, ?int $personelId, bool $bosTarihOlusturma = false): array
    {
        $kolonlar = ['k.id', 'k.fiyat', 'k.personel_id', 'k.'.$tur->katalogFk().' as katalog_id', 'c.'.$tur->adKolonu().' as ad'];
        if ($tur === KalemTuru::Hizmet) {
            array_push($kolonlar, 'k.randevu_id', 'k.seans_sayisi');
        }

        return DB::table($tur->kalemTablosu().' as k')
            ->join('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
            ->leftJoin($tur->katalogTablosu().' as c', 'c.id', '=', 'k.'.$tur->katalogFk())
            ->where('a.salon_id', $salonId)
            ->when($bosTarihOlusturma, fn ($q) => $q->whereBetween(DB::raw(SatisKalemSorgusu::satisTarihiSql('a')), [$t1, $t2]))
            ->when(! $bosTarihOlusturma, fn ($q) => $q->whereBetween('a.tarih', [$t1, $t2]))
            ->when($personelId, fn ($q) => $q->where('k.personel_id', $personelId))
            ->orderBy('a.id')->orderBy('k.id')
            ->get($kolonlar)
            ->map(fn ($k) => (array) $k)
            ->all();
    }

    /** @return callable(array<int,int>):mixed kalem listesinin SQL SUM tahsilat toplami (eski ->sum('tutar')) */
    private function kazanc(KalemTuru $tur): callable
    {
        return fn (array $ids) => DB::table($tur->tahsilatTablosu())->whereIn($tur->tahsilatFk(), $ids)->sum('tutar');
    }
}
