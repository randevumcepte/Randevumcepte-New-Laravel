<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Stok\Birim;
use App\Domain\Stok\HareketTipi;
use App\Domain\Stok\UrunTipi;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Stok\StokRaporServisi;
use App\Services\Stok\Tanim\DepoServisi;
use App\Services\Stok\Tanim\KategoriServisi;
use App\Services\Stok\Tanim\TedarikciServisi;
use App\Services\Stok\UrunServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stok Yonetimi — ekran duzeyi OKUMA uclari (ince controller).
 *   GET /api/v2/stok/ozet?salon_id=
 *   GET /api/v2/stok/form-verisi?salon_id=   (secenekler + yetkiler — UI'da sabit liste yok)
 *   GET /api/v2/stok/dusuk-stok?salon_id=
 *   GET /api/v2/stok/hareketler?salon_id=&urun_id=&depo_id=&hareket_tipi=&baslangic=&bitis= + ListeIstegi
 *   GET /api/v2/stok/musteri-ara?salon_id=&q=  (hizli satis musteri secici)
 */
class StokController extends StokTabanController
{
    public function __construct(private StokRaporServisi $rapor) {}

    public function ozet(Request $request)
    {
        [, $salonId] = $this->salonYetki($request, self::GORUNTULE);

        return ApiCevap::basarili($this->rapor->ozet($salonId));
    }

    public function formVerisi(Request $request, KategoriServisi $kat, DepoServisi $depo, TedarikciServisi $ted)
    {
        [$uid, $salonId] = $this->salonYetki($request, self::URUN_OKU);
        $y = fn (string $k) => $this->yetkiVar($uid, $salonId, $k);

        return ApiCevap::basarili([
            'kategoriler'            => $kat->listele($salonId),
            'depolar'                => $depo->listele($salonId),
            'tedarikciler'           => $ted->listele($salonId),
            'birimler'               => Birim::secenekler(),
            'tipler'                 => UrunTipi::secenekler(),
            'hareket_tipleri'        => HareketTipi::secenekler(HareketTipi::cases()),
            'manuel_hareket_tipleri' => HareketTipi::secenekler(HareketTipi::manuelSecilebilir()),
            'odeme_yontemleri'       => DB::table('odeme_yontemleri')->orderBy('id')->get(['id', 'odeme_yontemi'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'ad' => $o->odeme_yontemi])->all(),
            'yetkiler' => [
                'tanim_olustur'   => $y('urun.tanim_olustur'),
                'stok_giris'      => $y('urun.stok_giris'),
                'stok_sayim'      => $y('urun.stok_sayim'),
                'tedarikci_yonet' => $y('urun.tedarikci_yonet'),
                'sat'             => $y('urun.sat'),
                'tahsilat_al'     => $y('satis.tahsilat_al'),
            ],
            'yazma_aktif'          => (bool) config('stok.yazma_aktif'),
            'tahsilat_yazma_aktif' => (bool) config('tahsilat.yazma_aktif'),
        ]);
    }

    public function dusukStok(Request $request, UrunServisi $urun)
    {
        [, $salonId] = $this->salonYetki($request, self::GORUNTULE);
        $liste = ListeIstegi::istekten($request, 100, UrunServisi::SIRALANABILIR, 'stok_adedi', 'asc')->dizi();

        return ApiCevap::basarili($urun->listele($salonId, ['seviye' => 'uyari', 'tumu' => true], $liste)['data']);
    }

    public function hareketler(Request $request)
    {
        [, $salonId] = $this->salonYetki($request, self::GORUNTULE);
        $liste = ListeIstegi::istekten($request, 25, StokRaporServisi::HAREKET_SIRALANABILIR, 'tarih', 'desc');

        return ApiCevap::basarili($this->rapor->hareketler(
            $salonId,
            $request->only(['urun_id', 'depo_id', 'hareket_tipi', 'baslangic', 'bitis']),
            $liste->dizi(),
        ));
    }

    /** Randevu formuyla AYNI arama servisi; burada kapi urun.sat. Telefon musteri.telefon_gor ile. */
    public function musteriAra(Request $request, RandevuFormServisi $form)
    {
        [$uid, $salonId] = $this->salonYetki($request, ['urun.sat']);

        return ApiCevap::basarili($form->musteriAra($salonId, (string) $request->input('q', ''), $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }
}
