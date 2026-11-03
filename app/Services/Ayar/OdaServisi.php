<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\KaynakTuru;
use App\Services\Satis\KatalogServisi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Ayarlar → Odalar: eski odaekleduzenle / odadetayigetir + v1 odagetir, odaekleduzenle,
 * oda_detay, oda_personel_listesi, oda_hizmet_listesi'nin TEK karsiligi.
 * Ortak liste/durum/sil/sira TakvimKaynakServisi'nde.
 *
 * odalar.personel_id = ilk secili personel (eski takvim uyumu); oda_personelleri +
 * oda_sunulan_hizmetler sil-ve-yaz. Secilen kimlikler bu salona ait olanlarla suzulur
 * (eskide baska salonun personeli/hizmeti yazilabiliyordu).
 */
class OdaServisi implements TakvimKaynakYonetimi
{
    private const TUR = KaynakTuru::Oda;

    public function __construct(
        private TakvimKaynakServisi $kaynak,
        private KatalogServisi $katalog,
    ) {}

    public function tur(): KaynakTuru
    {
        return self::TUR;
    }

    /** @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $o */
    public function listele(int $salonId, array $o, bool $tumu = false): array
    {
        return $this->kaynak->listele(self::TUR, $salonId, $o, $tumu,
            fn (array $satirlar) => $this->iliskiEkle($satirlar), ['personel_metni', 'hizmet_metni']);
    }

    public function detay(int $salonId, int $id): array
    {
        $satir = $this->iliskiEkle([$id => $this->kaynak->satir(self::TUR, $salonId, $id)])[$id];

        return $satir + [
            'personel_ids' => array_column($satir['personeller'], 'id'),
            'hizmet_ids'   => array_column($satir['hizmetler'], 'id'),
        ];
    }

    /** Oda formu secenekleri: aktif personeller + salonun aktif hizmetleri. */
    public function formVerisi(int $salonId): array
    {
        return [
            'personeller' => DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
                ->orderBy('personel_adi')->get(['id', 'personel_adi'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'personel_adi' => (string) $p->personel_adi])->all(),
            'hizmetler' => array_map(fn ($h) => ['id' => $h['hizmet_id'], 'hizmet_adi' => $h['ad']], $this->katalog->hizmetler($salonId)),
        ];
    }

    /** @param  array{oda_adi?:string, personel_ids?:list<int>, hizmet_ids?:list<int>}  $g */
    public function kaydet(int $salonId, int $yapanId, ?int $id, array $g): array
    {
        $yeni = $id === null;
        if (! $yeni) {
            $this->kaynak->bul(self::TUR, $salonId, $id);
        }
        $secenek = $this->formVerisi($salonId);
        $personeller = $this->suz($g['personel_ids'] ?? null, array_column($secenek['personeller'], 'id'));
        $hizmetler = $this->suz($g['hizmet_ids'] ?? null, array_column($secenek['hizmetler'], 'id'));

        $id = DB::transaction(function () use ($salonId, $id, $g, $personeller, $hizmetler) {
            if ($id === null) {
                $id = $this->kaynak->olustur(self::TUR, $salonId, trim($g['oda_adi']));
            } elseif (isset($g['oda_adi'])) {
                $this->kaynak->adGuncelle(self::TUR, $id, trim($g['oda_adi']));
            }
            $simdi = now();
            if ($personeller !== null) {
                DB::table('odalar')->where('id', $id)->update(['personel_id' => $personeller[0] ?? null]);
                DB::table('oda_personelleri')->where('oda_id', $id)->delete();
                DB::table('oda_personelleri')->insert(array_map(fn ($p) => [
                    'oda_id' => $id, 'salon_id' => $salonId, 'personel_id' => $p, 'created_at' => $simdi, 'updated_at' => $simdi,
                ], $personeller));
            }
            if ($hizmetler !== null) {
                DB::table('oda_sunulan_hizmetler')->where('oda_id', $id)->delete();
                DB::table('oda_sunulan_hizmetler')->insert(array_map(fn ($h) => [
                    'oda_id' => $id, 'salon_id' => $salonId, 'hizmet_id' => $h, 'created_at' => $simdi, 'updated_at' => $simdi,
                ], $hizmetler));
            }

            return $id;
        });

        $detay = $this->detay($salonId, $id);
        AktiviteLog::yaz($salonId, $yapanId, $yeni ? 'oda_ekle' : 'oda_guncelle', 'oda', $id,
            $detay['ad'], $yeni ? 'Yeni oda eklendi' : 'Oda bilgileri güncellendi');

        return $detay;
    }

    /**
     * Personel + hizmet adlarini toplu ekler (Flutter Oda.personeller[{id, personel_adi}] ile uyumlu).
     *
     * @param  array<int,array>  $satirlar  id => satir
     */
    private function iliskiEkle(array $satirlar): array
    {
        $idler = array_keys($satirlar);
        $personel = DB::table('oda_personelleri as op')->join('salon_personelleri as p', 'p.id', '=', 'op.personel_id')
            ->whereIn('op.oda_id', $idler)->orderBy('p.personel_adi')->get(['op.oda_id', 'p.id', 'p.personel_adi'])->groupBy('oda_id');
        $hizmet = DB::table('oda_sunulan_hizmetler as oh')->join('hizmetler as h', 'h.id', '=', 'oh.hizmet_id')
            ->whereIn('oh.oda_id', $idler)->orderBy('h.hizmet_adi')->get(['oh.oda_id', 'h.id', 'h.hizmet_adi'])->groupBy('oda_id');

        foreach ($satirlar as $id => &$s) {
            $s['personeller'] = $personel->get($id, collect())->unique('id')
                ->map(fn ($p) => ['id' => (int) $p->id, 'personel_adi' => (string) $p->personel_adi])->values()->all();
            $s['hizmetler'] = $hizmet->get($id, collect())->unique('id')
                ->map(fn ($h) => ['id' => (int) $h->id, 'hizmet_adi' => trim((string) $h->hizmet_adi)])->values()->all();
            $s['personel_metni'] = implode(', ', array_column($s['personeller'], 'personel_adi'));
            $s['hizmet_metni'] = implode(', ', array_column($s['hizmetler'], 'hizmet_adi'));
        }

        return $satirlar;
    }

    /** null = alan gonderilmedi (dokunma); dizi = gecerli + tekil kimlikler (sira korunur). */
    private function suz(?array $istenen, array $gecerli): ?array
    {
        if ($istenen === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map('intval', $istenen), fn ($i) => in_array($i, $gecerli, true))));
    }
}
