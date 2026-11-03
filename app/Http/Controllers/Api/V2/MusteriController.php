<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Musteri\Segment;
use App\Exceptions\GecersizIslemException;
use App\Http\Requests\Musteri\MusteriKaydetIstegi;
use App\Http\Requests\Musteri\SaglikIstegi;
use App\Services\Musteri\MusteriListeServisi;
use App\Services\Musteri\MusteriOkumaServisi;
use App\Services\Musteri\MusteriYazServisi;
use App\Services\Randevu\RandevuListeServisi;
use App\Services\Yetki\KapsamServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Musteri API v2 (ince controller) — web paneli (/v2/musteriler, /v2/musteri/{id}) ve
 * Flutter AYNI uclari kullanir. v1 esleme tablosu: docs/flutter-uyum-yol-haritasi.md.
 *
 *   GET    /musteri?salon_id=&durum=0..4 + ListeIstegi  liste + sekme sayaclari (musteri.liste_gor)
 *   GET    /musteri/sayilar?salon_id=                    sekme sayaclari
 *   GET    /musteri/ara?salon_id=&q=&limit=             hafif secici (randevu/satis formlari)
 *   GET    /musteri/form-verisi                          referans/cinsiyet/cilt/saglik sorulari
 *   GET    /musteri/{id}?salon_id=                       detay + ekran ayarlari + yetkiler
 *   GET    /musteri/{id}/randevu-notlari                 not zaman cizelgesi (sayfali)
 *   GET    /musteri/{id}/randevular                      randevu listesi (sayfali)
 *   POST   /musteri · PATCH /musteri/{id}                ekle / duzenle    (musteri.ekle_duzenle)
 *   DELETE /musteri/{id}                                 portfoyden cikar  (musteri.sil)
 *   PATCH  /musteri/{id}/kara-liste  {kara_liste}        (ekle_duzenle + personel rolu degil)
 *   PATCH  /musteri/{id}/saglik                          (ekle_duzenle + personel rolu degil)
 * Satislar sekmesi: mevcut GET /satislar?musteri_id= (musteri.gecmis_satis_gor istisnasi orada).
 */
class MusteriController extends MusteriTabanController
{
    /** Secici: musteri listesi yetkisi olmayan ama randevu/satis yapan kullanicilar da arar. */
    private const SECICI = ['musteri.liste_gor', 'randevu.olustur', 'randevu.duzenle_iptal', 'satis.adisyon_olustur', 'satis.tahsilat_al'];

    public function __construct(
        private MusteriListeServisi $liste,
        private MusteriOkumaServisi $okuma,
        private MusteriYazServisi $yaz,
    ) {}

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, 'musteri.liste_gor');
        $l = ListeIstegi::istekten($request, 10, MusteriListeServisi::SIRALANABILIR, null, 'desc');

        $veri = $this->liste->listele(
            $salonId,
            Segment::filtre($request->input('durum', Segment::TUMU)),
            $this->portfoy()->kisitliPersonel($uid, $salonId),
            $l,
            $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'),
        );

        return ApiCevap::basarili($veri + [
            'ayarlar'  => $this->okuma->salonAyarlari($salonId),
            'yetkiler' => $this->yetkiler($uid, $salonId),
        ]);
    }

    public function sayilar(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, 'musteri.liste_gor');

        return ApiCevap::basarili($this->liste->sayilar($salonId, $this->portfoy()->kisitliPersonel($uid, $salonId)));
    }

    public function ara(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::SECICI);
        $q = trim((string) ($request->input('q') ?? $request->input('ara') ?? ''));
        if (mb_strlen($q) < 2) {
            throw new GecersizIslemException('Aramak için en az 2 karakter girin.');
        }

        return ApiCevap::basarili($this->liste->ara(
            $salonId, mb_substr($q, 0, 100),
            $this->portfoy()->kisitliPersonel($uid, $salonId),
            $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor'),
            (int) $request->input('limit', 20),
        ));
    }

    public function formVerisi(Request $request)
    {
        $this->salonDenetle($request, ['musteri.liste_gor', 'musteri.ekle_duzenle', ...self::DETAY]);

        return ApiCevap::basarili($this->okuma->formVerisi());
    }

    public function goster(Request $request, int $id, KapsamServisi $kapsam)
    {
        [$uid, $salonId, $kisit] = $this->musteriDenetle($request, $id, self::DETAY);
        $yetkiler = $this->yetkiler($uid, $salonId);
        $personelRolu = in_array(5, $kapsam->salonRolleri($uid, $salonId), true);

        // Personel rolu: saglik / kara liste / belgeler eski ekranda gizliydi.
        $yetkiler['saglik'] = $yetkiler['ekle_duzenle'] && ! $personelRolu;
        $yetkiler['kara_liste'] = $yetkiler['saglik'];
        $yetkiler['belgeler'] = ! $personelRolu;

        return ApiCevap::basarili([
            'musteri'  => $this->okuma->detay($salonId, $id, $kisit, ['telefon' => $yetkiler['telefon_gor'], 'saglik' => ! $personelRolu]),
            'ayarlar'  => $this->okuma->salonAyarlari($salonId),
            'yetkiler' => $yetkiler,
        ]);
    }

    public function randevuNotlari(Request $request, int $id)
    {
        [, $salonId] = $this->musteriDenetle($request, $id, self::DETAY);

        return ApiCevap::basarili($this->okuma->randevuNotlari($salonId, $id, ListeIstegi::istekten($request, 50)));
    }

    public function randevular(Request $request, int $id, RandevuListeServisi $randevu)
    {
        [$uid, $salonId] = $this->musteriDenetle($request, $id, self::DETAY);
        // Eski randevu_liste_getir: randevu.tum_personel_gor yoksa yalniz kendi kalemleri.
        $kisit = $this->yetkiVar($uid, $salonId, 'randevu.tum_personel_gor') ? null : $this->portfoy()->personelId($uid, $salonId);

        return ApiCevap::basarili($randevu->listele(
            $salonId,
            ['musteri_id' => $id, 'kisit_personel_id' => $kisit],
            ListeIstegi::istekten($request, 10, RandevuListeServisi::SIRALANABILIR, 'tarih', 'desc'),
            [
                'onayla'        => $this->yetkiVar($uid, $salonId, 'randevu.onayla'),
                'duzenle_iptal' => $this->yetkiVar($uid, $salonId, 'randevu.duzenle_iptal'),
            ],
        ));
    }

    // ===================== YAZMA (kill-switch: MUSTERI_V2_YAZMA_AKTIF) =====================

    public function kaydet(MusteriKaydetIstegi $request, ?int $id = null)
    {
        if ($id !== null) {
            [$uid, $salonId] = $this->musteriDenetle($request, $id, 'musteri.ekle_duzenle');
        } else {
            [$uid, $salonId] = $this->salonDenetle($request, 'musteri.ekle_duzenle');
        }
        $this->yazmaAcikMi();

        $sonuc = $this->yaz->kaydet(['salon_id' => $salonId, 'musteri_id' => $id, 'yapan_id' => $uid] + $request->veri());

        return ApiCevap::basarili($sonuc, $id !== null ? 'Müşteri bilgileri güncellendi.' : 'Müşteri kaydedildi.', $id === null ? 201 : 200);
    }

    public function sil(Request $request, int $id)
    {
        [$uid, $salonId] = $this->musteriDenetle($request, $id, 'musteri.sil');
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->sil($salonId, $id, $uid), 'Müşteri silindi.');
    }

    public function karaListe(Request $request, int $id)
    {
        [$uid, $salonId] = $this->musteriDenetle($request, $id, 'musteri.ekle_duzenle');
        $this->yoneticiDenetle($uid, $salonId);
        $this->yazmaAcikMi();

        $kara = filter_var($request->input('kara_liste'), FILTER_VALIDATE_BOOLEAN);

        return ApiCevap::basarili(
            $this->yaz->karaListe($salonId, $id, $kara, $this->okuma->salonAyarlari($salonId)['kara_liste_aktif'], $uid),
            $kara ? 'Müşteri kara listeye alındı.' : 'Müşteri kara listeden çıkarıldı.',
        );
    }

    public function saglik(SaglikIstegi $request, int $id)
    {
        [$uid, $salonId] = $this->musteriDenetle($request, $id, 'musteri.ekle_duzenle');
        $this->yoneticiDenetle($uid, $salonId);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->yaz->saglikKaydet($salonId, $id, $request->validated(), $uid), 'Sağlık bilgileri kaydedildi.');
    }

    // ---------------------------------------------------------------------

    /**
     * Sayfa duzeyi yetkiler — web ve Flutter buton kararini YENIDEN HESAPLAMAZ.
     *
     * @return array<string,bool>
     */
    private function yetkiler(int $uid, int $salonId): array
    {
        $y = fn (string $k) => $this->yetkiVar($uid, $salonId, $k);

        return [
            'detay_gor'      => $y('musteri.detay_gor') || $y('musteri.not_yaz') || $y('musteri.gecmis_satis_gor'),
            'ekle_duzenle'   => $y('musteri.ekle_duzenle'),
            'sil'            => $y('musteri.sil'),
            'telefon_gor'    => $y('musteri.telefon_gor'),
            'not_yaz'        => $y('musteri.not_yaz'),
            'satis_gor'      => $y('musteri.gecmis_satis_gor'),
            'seans_gor'      => $y('paket.seans_takip'),
            'olcum_gor'      => $y('randevu.takvim_gor'),
            'olcum_sil'      => $y('randevu.duzenle_iptal'),
            'adisyon_olustur' => $y('satis.adisyon_olustur'),
            'tahsilat_al'    => $y('satis.tahsilat_al'),
            'anket'          => $y('pazarlama.anket_yonet'),
            'whatsapp'       => $y('pazarlama.whatsapp_gonder'),
        ];
    }
}
