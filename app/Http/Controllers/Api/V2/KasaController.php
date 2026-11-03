<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Http\Requests\Kasa\ParaHareketIstegi;
use App\Models\Personeller;
use App\Services\Kasa\GunSonuServisi;
use App\Services\Kasa\KasaRaporServisi;
use App\Services\Kasa\KasaYazServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * KASA RAPORU — eski isletmeyonetim/kasadefteri (+ kasaraporufiltre, kasagelirdetay,
 * gunsonuraporu, devreden-aylar, kasayaparaekle, kasadanparaal, paracekmeonaykodugonder,
 * tahsilatkaldir kasa dali) ve mobil v1 kasa uclarinin TEK v2 karsiligi.
 *
 *   GET    /kasa/ozet?salon_id=&donem=&baslangic=&bitis=&odeme_yontemi_id=      rapor.kasa
 *   GET    /kasa/gelirler?…&ara=&sirala=&yon=&page=&per_page=                     rapor.kasa
 *   GET    /kasa/giderler?…                                                       rapor.kasa + finans.masraf_gor
 *   GET    /kasa/gelir/{id}?salon_id=                                             rapor.kasa
 *   GET    /kasa/gun-sonu?salon_id=&donem=&baslangic=&bitis=  (varsayilan bugun)  rapor.kasa
 *   GET    /kasa/aylik?salon_id=&yil=                                             rapor.kasa
 *   POST   /kasa/para-giris            {salon_id, tarih, tutar, odeme_yontemi_id, personel_id, aciklama}
 *   POST   /kasa/para-cikis/onay-kodu  {salon_id, tutar}
 *   POST   /kasa/para-cikis            {… + onay_kodu}                            finans.kasa_giris_cikis
 *   DELETE /kasa/gelir/{id}?salon_id=&onay_kodu=                                  satis.tahsilat_sil
 *
 * KVKK: kimlik + KAPSAM her ucta (eskiden ?sube ile baska salonun kasasi okunabiliyordu).
 * Yazmalar KASA_V2_YAZMA_AKTIF kapaliyken 409 (kapsam/yetki SONRASI — flag durumu sizmaz).
 */
class KasaController extends RaporTabanController
{
    protected ?string $yazmaAlani = 'kasa';

    protected string $yazmaKapaliMesaj = 'Kasa v2 yazma şu an kapalı';

    public function __construct(private KasaRaporServisi $rapor, private KasaYazServisi $yaz) {}

    public function ozet(Request $r)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.kasa');

        return ApiCevap::basarili($this->rapor->ozet($salonId, $this->donem($r), $this->odemeYontemi($r)));
    }

    public function gelirler(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.kasa');

        return ApiCevap::basarili($this->rapor->gelirler(
            $salonId, $this->donem($r), $this->odemeYontemi($r),
            $this->liste($r, KasaRaporServisi::GELIR_SIRALANABILIR, 'odeme_tarihi'),
            $this->yetkiVar($uid, $salonId, 'satis.tahsilat_sil'),
        ));
    }

    public function giderler(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.kasa');
        $this->salonYetki($r, 'finans.masraf_gor');

        return ApiCevap::basarili($this->rapor->giderler(
            $salonId, $this->donem($r), $this->odemeYontemi($r),
            $this->liste($r, KasaRaporServisi::GIDER_SIRALANABILIR, 'tarih'),
            $this->yetkiVar($uid, $salonId, 'finans.masraf_ekle'),
        ));
    }

    public function gelirDetay(Request $r, int $id)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.kasa');

        return ApiCevap::basarili($this->rapor->gelirDetay($salonId, $id));
    }

    public function gunSonu(Request $r, GunSonuServisi $gunSonu)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.kasa');

        return ApiCevap::basarili($gunSonu->rapor($salonId, $this->donem($r, 'bugun')));
    }

    public function aylik(Request $r)
    {
        [, $salonId] = $this->salonYetki($r, 'rapor.kasa');
        $yil = $r->integer('yil') ?: (int) date('Y');
        if ($yil < 2000 || $yil > (int) date('Y') + 1) {
            throw new GecersizIslemException('yil geçersiz');
        }

        return ApiCevap::basarili($this->rapor->aylik($salonId, $yil));
    }

    public function paraGiris(ParaHareketIstegi $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'finans.kasa_giris_cikis');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->paraGiris($salonId, $uid, $this->personelli($r, $salonId)), 'Kasaya para eklendi.');
    }

    public function paraCikisKodu(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'finans.kasa_giris_cikis');
        $tutar = (float) $r->input('tutar');
        if ($tutar <= 0) {
            throw new GecersizIslemException('Önce çekilecek tutarı girin');
        }
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->paraCikisKodu($salonId, $uid, $tutar), 'Onay kodu yetkililere gönderildi.');
    }

    public function paraCikis(ParaHareketIstegi $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'finans.kasa_giris_cikis');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->paraCikis($salonId, $uid, $this->personelli($r, $salonId)), 'Kasadan para çekildi.');
    }

    public function gelirSil(Request $r, int $id)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'satis.tahsilat_sil');
        $this->yazmaAcikMi();
        $sonuc = $this->yaz->gelirSil($salonId, $uid, $id, $r->input('onay_kodu'));

        return ApiCevap::basarili($sonuc, $sonuc['silindi'] ? 'Kayıt silindi.' : 'Onay kodu hesap sahibine gönderildi.');
    }

    private function odemeYontemi(Request $r): ?int
    {
        return $r->integer('odeme_yontemi_id') ?: null;
    }

    /** Dogrulanmis govde; personel_id verilmisse ayni salona ait olmali. */
    private function personelli(ParaHareketIstegi $r, int $salonId): array
    {
        $v = $r->validated();
        if (! empty($v['personel_id']) && ! Personeller::salondaMi((int) $v['personel_id'], $salonId)) {
            throw new GecersizIslemException('Seçilen personel bu işletmeye ait değil');
        }

        return $v;
    }
}
