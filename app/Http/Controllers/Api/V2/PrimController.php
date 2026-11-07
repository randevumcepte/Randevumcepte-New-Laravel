<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Rapor\MobilPrimHesap;
use App\Exceptions\YetkisizErisimException;
use App\Services\Prim\MobilPrimServisi;
use App\Services\Prim\PrimOdemeServisi;
use App\Services\Rapor\RaporSecenekServisi;
use App\Support\ApiCevap;
use App\Support\Idempotency;
use Illuminate\Http\Request;

/**
 * PRIM & HAK EDIS (mobil) — eski personelPrimHesaplaAyYil, primHakedisToplu, primOde, primOdemeListesi, primOdemeSil,
 * primHareketEkle, primHareketSil. Hesap eski mobil kuraliyla (Domain/Rapor/MobilPrimHesap); web hak edis formulu ayridir.
 *
 *   GET    /prim/hakedis?salon_id=&ay=&yil=&gun=          personel.prim_hakedis_gor → tum personel; yoksa yalniz kendi satiri
 *   GET    /prim/personel/{id}?salon_id=&ay=&yil=         kendi kaydi | personel.liste_gor | personel.prim_hakedis_gor
 *   GET    /prim/odemeler?salon_id=&personel_id=&donem=   kendi kaydi | personel.odeme_yap
 *   POST   /prim/odeme   {salon_id, personel_id, donem, tutar, odeme_tipi, odeme_tarihi, odeme_yontemi, aciklama, prim_gun, idempotency_key}
 *   DELETE /prim/odeme/{id}?salon_id=
 *   POST   /prim/hareket {salon_id, personel_id, tip, tutar, tarih, aciklama, idempotency_key}
 *   DELETE /prim/hareket/{id}?salon_id=                   yazmalar personel.odeme_yap
 *
 * Maskeleme: personel.maas_tutar_gor yoksa baskasinin maas, prim orani, hak edis ve odeme tutarlari null gider; kendi
 * satiri acik gider. Telefon: kendi satiri veya personel.liste_gor. Yazmalar kasa kill-switch'inde (masraf da yazilir).
 */
class PrimController extends RaporTabanController
{
    protected ?string $yazmaAlani = 'kasa';

    protected string $yazmaKapaliMesaj = 'Kasa v2 yazma şu an kapalı';

    public function __construct(
        private MobilPrimServisi $okuma,
        private PrimOdemeServisi $yaz,
        private RaporSecenekServisi $secenek,
    ) {}

    public function hakedis(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, []);
        $kendi = $this->secenek->bagliPersonel($uid, $salonId);
        $tumu = $this->yetkiVar($uid, $salonId, 'personel.prim_hakedis_gor');
        $maasGor = $this->yetkiVar($uid, $salonId, 'personel.maas_tutar_gor');
        $telefonGor = $this->yetkiVar($uid, $salonId, 'personel.liste_gor');

        $sonuc = $this->okuma->hakedis($salonId, $r->input('ay'), $r->input('yil'), $r->input('gun'), date('Y-m-d H:i:s'), $tumu ? null : $kendi);
        $sonuc['personeller'] = array_map(function (array $s) use ($kendi, $maasGor, $telefonGor) {
            if ((int) $s['personel_id'] === $kendi) {
                return $s;
            }
            if (! $telefonGor) {
                $s['cep_telefon'] = '';
            }

            return $maasGor ? $s : MobilPrimHesap::satirMaskele($s);
        }, $sonuc['personeller']);

        return ApiCevap::basarili($sonuc);
    }

    public function personel(Request $r, int $id)
    {
        [$uid, $salonId] = $this->salonYetki($r, []);
        $kendi = $this->secenek->bagliPersonel($uid, $salonId) === $id;
        if (! $kendi && ! $this->yetkiVar($uid, $salonId, 'personel.liste_gor') && ! $this->yetkiVar($uid, $salonId, 'personel.prim_hakedis_gor')) {
            throw new YetkisizErisimException('Bu işlem için yetkiniz yok');
        }

        $sonuc = $this->okuma->aylik($salonId, $id, $r->input('ay'), $r->input('yil'), date('Y-m-d H:i:s'));

        return ApiCevap::basarili($kendi || $this->yetkiVar($uid, $salonId, 'personel.maas_tutar_gor') ? $sonuc : MobilPrimHesap::aylikMaskele($sonuc));
    }

    public function odemeler(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, []);
        $personelId = $r->integer('personel_id');
        if ($this->secenek->bagliPersonel($uid, $salonId) !== $personelId && ! $this->yetkiVar($uid, $salonId, 'personel.odeme_yap')) {
            throw new YetkisizErisimException('Bu işlem için yetkiniz yok');
        }

        return ApiCevap::basarili($this->okuma->odemeListesi($salonId, $personelId, $r->input('donem'), date('Y-m')));
    }

    public function ode(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'personel.odeme_yap');
        $this->yazmaAcikMi();

        return ApiCevap::basarili(Idempotency::calistir('prim_ode:'.$salonId, (string) $r->input('idempotency_key'), $uid, $salonId,
            fn () => $this->yaz->ode($salonId, $uid, $r->all())));
    }

    public function odemeSil(Request $r, int $id)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'personel.odeme_yap');
        $this->yazmaAcikMi();
        $this->yaz->odemeSil($salonId, $uid, $id);

        return ApiCevap::basarili(null);
    }

    public function hareketEkle(Request $r)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'personel.odeme_yap');
        $this->yazmaAcikMi();

        return ApiCevap::basarili(Idempotency::calistir('prim_hareket:'.$salonId, (string) $r->input('idempotency_key'), $uid, $salonId,
            fn () => $this->yaz->hareketEkle($salonId, $uid, $r->all())));
    }

    public function hareketSil(Request $r, int $id)
    {
        [$uid, $salonId] = $this->salonYetki($r, 'personel.odeme_yap');
        $this->yazmaAcikMi();
        $this->yaz->hareketSil($salonId, $uid, $id);

        return ApiCevap::basarili(null);
    }
}
