<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\SistemYonetim\UyelikKurallari;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\SalonKapsami;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Salon listesi: arama + temsilci + durum + tip filtreleri, hizli filtre sayilari. CSV ayni sorguyu kullanir. */
class SalonListeServisi
{
    /** ListeIstegi sirala => kolon. */
    public const SIRALAMA = ['id' => 'id', 'salon' => 'salon_adi', 'kayit' => 'created_at', 'bitis' => 'uyelik_bitis_tarihi'];

    private const KOLONLAR = ['id', 'salon_adi', 'telefon_1', 'il_id', 'ilce_id', 'yetkili_adi', 'yetkili_telefon', 'musteri_yetkili_id',
        'created_at', 'uyelik_bitis_tarihi', 'demo_hesabi', 'askiya_alindi'];

    public function __construct(private SalonKapsami $kapsam, private SalonIletisim $iletisim) {}

    /**
     * @param  array{page:int,per_page:int,ara:?string,sirala:?string,yon:string}  $liste
     * @param  array{durum?:?string,tip?:?string,mt?:?int}  $filtre
     */
    public function listele(array $liste, array $filtre, SistemYoneticisi $yonetici): array
    {
        $taban = $this->taban($liste['ara'], $filtre['mt'] ?? null, $yonetici);
        $sayilar = [
            'tumu'  => (clone $taban)->count(),
            'aktif' => $this->aktifler(clone $taban)->count(),
            'demo'  => $this->demolar(clone $taban)->count(),
        ];

        $sorgu = $this->secili($taban, $filtre);
        $toplam = (clone $sorgu)->count();
        $satirlar = $sorgu->orderBy(self::SIRALAMA[$liste['sirala'] ?? ''] ?? 'id', $liste['yon'])->orderByDesc('id')
            ->forPage($liste['page'], $liste['per_page'])->get(self::KOLONLAR);

        return ['data' => $this->satirlar($satirlar), 'sayilar' => $sayilar] + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    /** Filtrelenmis sorgu (siralama ve kolon cagirana ait). */
    public function sorgu(?string $ara, array $filtre, SistemYoneticisi $yonetici): Builder
    {
        return $this->secili($this->taban($ara, $filtre['mt'] ?? null, $yonetici), $filtre);
    }

    /** Durum (askida/aktif) ve tip (demo/aktif) birlikte uygulanir; hizli filtre sayilari bundan bagimsizdir. */
    private function secili(Builder $taban, array $filtre): Builder
    {
        $sorgu = clone $taban;
        $durum = $filtre['durum'] ?? 'hepsi';
        if ($durum === 'aktif' || $durum === 'askida') {
            $sorgu->where('askiya_alindi', $durum === 'askida' ? 1 : 0);
        }

        return match ($filtre['tip'] ?? 'tumu') {
            'demo'  => $this->demolar($sorgu),
            'aktif' => $this->aktifler($sorgu),
            default => $sorgu,
        };
    }

    private function taban(?string $ara, ?int $temsilciId, SistemYoneticisi $yonetici): Builder
    {
        $sorgu = $this->kapsam->sorguyaUygula(DB::table('salonlar'), $yonetici);
        if ($temsilciId) {
            $sorgu->where('musteri_yetkili_id', $temsilciId);
        }
        if ($like = ListeIstegi::like($ara)) {
            $sorgu->where(fn ($w) => $w->where('salon_adi', 'like', $like)->orWhere('telefon_1', 'like', $like)
                ->orWhere('yetkili_telefon', 'like', $like)->orWhere('yetkili_adi', 'like', $like));
        }

        return $sorgu;
    }

    private function demolar(Builder $sorgu): Builder
    {
        return $sorgu->where('demo_hesabi', 1);
    }

    private function aktifler(Builder $sorgu): Builder
    {
        return $sorgu->where('askiya_alindi', 0)->where('uyelik_bitis_tarihi', '>=', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('demo_hesabi')->orWhere('demo_hesabi', 0));
    }

    private function satirlar($salonlar): array
    {
        $iller = DB::table('il')->whereIn('id', $salonlar->pluck('il_id')->filter()->unique())->pluck('il_adi', 'id')->all();
        $ilceler = DB::table('ilce')->whereIn('id', $salonlar->pluck('ilce_id')->filter()->unique())->pluck('ilce_adi', 'id')->all();
        $temsilciler = DB::table('sistemyoneticileri')->whereIn('id', $salonlar->pluck('musteri_yetkili_id')->filter()->unique())->pluck('name', 'id')->all();
        $sahipler = $this->iletisim->hesapSahipleri($salonlar->pluck('id')->all());
        $simdi = time();

        return $salonlar->map(function ($s) use ($iller, $ilceler, $temsilciler, $sahipler, $simdi) {
            $uyelik = UyelikKurallari::ozet($s->uyelik_bitis_tarihi, $simdi);
            $kisi = $this->iletisim->kisi($s, $sahipler[(int) $s->id] ?? null);

            return [
                'id'        => (int) $s->id,
                'salon_adi' => $s->salon_adi,
                'telefon_1' => $s->telefon_1,
                'il_adi'    => $iller[(int) $s->il_id] ?? null,
                'ilce_adi'  => $ilceler[(int) $s->ilce_id] ?? null,
                'yetkili'   => $kisi['ad'],
                'telefon'   => $kisi['telefon'],
                'temsilci'  => $temsilciler[(int) $s->musteri_yetkili_id] ?? null,
                'kayit'     => $s->created_at,
                'askida'    => (int) $s->askiya_alindi === 1,
                'uyelik'    => $uyelik,
                'rozet'     => UyelikKurallari::rozet((int) $s->askiya_alindi === 1, (int) $s->demo_hesabi === 1, $uyelik['kalan_gun']),
            ];
        })->all();
    }
}
