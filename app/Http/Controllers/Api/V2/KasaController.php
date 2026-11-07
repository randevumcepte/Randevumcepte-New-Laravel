<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\GecersizIslemException;
use App\Http\Requests\Kasa\ParaHareketIstegi;
use App\Models\Personeller;
use App\Services\Kasa\GunSonuServisi;
use App\Services\Kasa\KasaRaporServisi;
use App\Services\Kasa\KasaYazServisi;
use App\Services\Kasa\MobilKasaServisi;
use App\Services\Rapor\RaporSecenekServisi;
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
 *
 * Mobil yolu (mobil=1, yalniz imzali mobil token): ozet, gelirler, giderler, gun-sonu, aylik eski mobil v1 hesabini ve
 * cevabini verir (Services/Kasa/MobilKasaServisi), filtre baslangic/bitis/odeme_yontemi_id, giderlerde harcayan.
 * Yetki eski mobil ekraninin giris kuraliyladir: kasa ekraninin menu yetkilerinden biri (gun sonu: rapor.kasa), kayitsiz Sekreter dahil.
 */
class KasaController extends RaporTabanController
{
    protected ?string $yazmaAlani = 'kasa';

    protected string $yazmaKapaliMesaj = 'Kasa v2 yazma şu an kapalı';

    public function __construct(private KasaRaporServisi $rapor, private KasaYazServisi $yaz, private MobilKasaServisi $mobil) {}

    public function ozet(Request $r)
    {
        if ($r->boolean('mobil')) {
            [, $salonId] = $this->mobilYetki($r, RaporSecenekServisi::KASA_GIRIS);

            return ApiCevap::basarili($this->mobil->ozet($salonId, $this->mobilFiltre($r)));
        }
        [, $salonId] = $this->salonYetki($r, 'rapor.kasa');

        return ApiCevap::basarili($this->rapor->ozet($salonId, $this->donem($r), $this->odemeYontemi($r)));
    }

    public function gelirler(Request $r)
    {
        if ($r->boolean('mobil')) {
            [, $salonId] = $this->mobilYetki($r, RaporSecenekServisi::KASA_GIRIS);

            return ApiCevap::basarili($this->mobil->tahsilatSayfasi($salonId, $this->mobilFiltre($r), max(1, $r->integer('page'))));
        }
        [$uid, $salonId] = $this->salonYetki($r, 'rapor.kasa');

        return ApiCevap::basarili($this->rapor->gelirler(
            $salonId, $this->donem($r), $this->odemeYontemi($r),
            $this->liste($r, KasaRaporServisi::GELIR_SIRALANABILIR, 'odeme_tarihi'),
            $this->yetkiVar($uid, $salonId, 'satis.tahsilat_sil'),
        ));
    }

    public function giderler(Request $r)
    {
        if ($r->boolean('mobil')) {
            [, $salonId] = $this->mobilYetki($r, RaporSecenekServisi::KASA_GIRIS);

            return ApiCevap::basarili($this->mobil->masrafSayfasi($salonId, $this->mobilFiltre($r), $r->input('harcayan'), max(1, $r->integer('page'))));
        }
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
        if ($r->boolean('mobil')) {
            [, $salonId] = $this->mobilYetki($r, 'rapor.kasa');
            $f = $this->mobilFiltre($r);
            $t1 = $f['tarih1'] ?? date('Y-m-d');

            return ApiCevap::basarili($this->mobil->gunSonu($salonId, $t1, $f['tarih2'] ?? $t1));
        }
        [, $salonId] = $this->salonYetki($r, 'rapor.kasa');

        return ApiCevap::basarili($gunSonu->rapor($salonId, $this->donem($r, 'bugun')));
    }

    public function aylik(Request $r)
    {
        [, $salonId] = $r->boolean('mobil') ? $this->mobilYetki($r, RaporSecenekServisi::KASA_GIRIS) : $this->salonYetki($r, 'rapor.kasa');
        $yil = $r->integer('yil') ?: (int) date('Y');
        if ($yil < 2000 || $yil > (int) date('Y') + 1) {
            throw new GecersizIslemException('yil geçersiz');
        }

        return ApiCevap::basarili($r->boolean('mobil') ? $this->mobil->aylik($salonId, $yil) : $this->rapor->aylik($salonId, $yil));
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

    /** @return array{tarih1:?string, tarih2:?string, odeme:?int} */
    private function mobilFiltre(Request $r): array
    {
        $g = $this->alanlariDogrula($r, [
            'baslangic'        => ['nullable', 'date_format:Y-m-d'],
            'bitis'            => ['nullable', 'date_format:Y-m-d'],
            'odeme_yontemi_id' => ['nullable', 'integer'],
        ]);

        return ['tarih1' => $g['baslangic'] ?? null, 'tarih2' => $g['bitis'] ?? null, 'odeme' => $g['odeme_yontemi_id'] ?? null];
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
