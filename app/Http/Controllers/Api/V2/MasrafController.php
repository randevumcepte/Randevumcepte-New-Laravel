<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Http\Requests\Kasa\MasrafIstegi;
use App\Models\Personeller;
use App\Services\Kasa\MasrafServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * MASRAF / PERSONEL GIDERI — eski masrafekleduzenle / masraf_detay / masrafSil (web) +
 * mobil masrafekleduzenle / masrafsil / masrafkategorileri.
 *
 *   GET    /masraf/form-verisi?salon_id=           finans.masraf_ekle | finans.masraf_gor
 *   GET    /masraf/{id}                            finans.masraf_gor | finans.masraf_ekle
 *   POST   /masraf        {salon_id, tarih, tutar, personel_gideri, harcayan_id, masraf_kategori_id, odeme_yontemi_id, aciklama}
 *   PATCH  /masraf/{id}   (ayni govde)             finans.masraf_ekle
 *   DELETE /masraf/{id}                            finans.masraf_ekle
 *
 * {id}'li uclarda salon KAYITTAN cozulur (eskiden salon kapsami yoktu). Yazmalar kasa ile
 * ayni kill-switch'te (KASA_V2_YAZMA_AKTIF).
 */
class MasrafController extends RaporTabanController
{
    private const GOR = ['finans.masraf_gor', 'finans.masraf_ekle'];

    protected ?string $yazmaAlani = 'kasa';

    protected string $yazmaKapaliMesaj = 'Kasa v2 yazma şu an kapalı';

    public function __construct(private MasrafServisi $servis) {}

    public function formVerisi(Request $r)
    {
        $this->salonYetki($r, self::GOR);

        return ApiCevap::basarili($this->servis->formVerisi());
    }

    public function goster(int $id)
    {
        $salonId = $this->kayitYetki($id, self::GOR);

        return ApiCevap::basarili($this->servis->getir($salonId, $id));
    }

    public function ekle(MasrafIstegi $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'finans.masraf_ekle');
        $this->yazmaAcikMi();
        $sonuc = $this->servis->kaydet($salonId, $uid, $this->dogrula($r, $salonId));

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function guncelle(MasrafIstegi $r, int $id)
    {
        $salonId = $this->kayitYetki($id, 'finans.masraf_ekle');
        $this->yazmaAcikMi();
        $sonuc = $this->servis->kaydet($salonId, (int) $this->kullanici()->id, $this->dogrula($r, $salonId), $id);

        return ApiCevap::basarili($sonuc, $sonuc['mesaj']);
    }

    public function sil(int $id)
    {
        $salonId = $this->kayitYetki($id, 'finans.masraf_ekle');
        $this->yazmaAcikMi();
        $this->servis->sil($salonId, (int) $this->kullanici()->id, $id);

        return ApiCevap::basarili(null, 'Masraf kaydı başarıyla kaldırıldı');
    }

    /** Kaydin salonu (yoksa 404) → kapsam + yetki. */
    private function kayitYetki(int $id, array|string $yetki): int
    {
        $salonId = $this->bulundu($this->servis->salonId($id), 'Masraf bulunamadı');
        app(GuvenlikKapisi::class)->denetle($this->kullanici()->id, $salonId, $yetki);

        return $salonId;
    }

    private function dogrula(MasrafIstegi $r, int $salonId): array
    {
        $v = $r->validated();
        if (! empty($v['harcayan_id']) && ! Personeller::salondaMi((int) $v['harcayan_id'], $salonId)) {
            throw new GecersizIslemException('Seçilen personel bu işletmeye ait değil');
        }

        return $v;
    }
}
