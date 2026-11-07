<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Form\OdemePlani;
use App\Services\Form\ArsivIslemServisi;
use App\Services\Form\ArsivListeServisi;
use App\Services\Form\FormEkranVerisiServisi;
use App\Services\Randevu\RandevuFormServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Services\Yetki\YetkiServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Form Yönetimi — ARŞİV API v2 controller (ince). Eski /isletmeyonetim/arsivyonetimi
 * ekranı + v1 arsivyukle / arsivformekleguncelle / formgonder / arsivonayla / arsiviptal
 * / haricibelgeekle / sozlesme-olustur karşılığı. v1'de bu uçların HİÇBİRİNDE kimlik /
 * kapsam / yetki yoktu; burada her uç GuvenlikKapisi'nden geçer.
 *
 * Yetki: sayfa = form.olustur VEYA form.gonder (eskiyle aynı).
 *   - Formu Gönder / Tekrar Gönder / Sözleşme: form.gonder (SMS gider)
 *   - form.gonder yoksa form kaydı oluşturulabilir ama gönderilmez (v1 sadece_kaydet)
 *   - Harici belge: form.olustur
 * Yazmalar config('form.yazma_aktif') kill-switch arkasında (kapalıyken 409).
 */
class ArsivController extends V2Controller
{
    protected ?string $yazmaAlani = 'form';

    private const SAYFA = ['form.olustur', 'form.gonder'];

    public function __construct(
        private ArsivListeServisi $liste,
        private ArsivIslemServisi $islem,
        private FormEkranVerisiServisi $ekran,
        private RandevuFormServisi $musteri,
        private GuvenlikKapisi $kapi,
        private YetkiServisi $yetki,
    ) {}

    /** GET /api/v2/form/arsiv?salon_id=&sekme=tumu|onayli|beklenen|iptal|harici&musteri_id=&ara=&page=&per_page=&sirala=&yon= */
    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::SAYFA);
        $istek = ListeIstegi::istekten($request, 10, ['tarih', 'musteri', 'baslik'], 'tarih');

        return ApiCevap::basarili($this->liste->liste(
            $salonId,
            (string) $request->input('sekme', 'tumu'),
            $request->integer('musteri_id') ?: null,
            $istek,
            ['gonder' => $this->yetkiler($uid, $salonId)['gonder']],
        ));
    }

    /** GET /api/v2/form/form-verisi?salon_id= — modal verileri + giriş yapanın form yetkileri */
    public function formVerisi(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::SAYFA);

        return ApiCevap::basarili($this->ekran->getir($salonId, $this->kullanici(), $this->yetkiler($uid, $salonId)));
    }

    /** GET /api/v2/form/musteri-ara?salon_id=&q= — müşteri seçici (randevu formuyla aynı arama) */
    public function musteriAra(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::SAYFA);

        return ApiCevap::basarili($this->musteri->musteriAra($salonId, (string) $request->input('q', ''), $this->telGor($uid, $salonId)));
    }

    /** GET /api/v2/form/musteri/{userId}?salon_id= — seçilen müşterinin telefonu (form gönder modalı) */
    public function musteriBilgi(Request $request, int $userId)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::SAYFA);

        return ApiCevap::basarili($this->islem->musteriBilgi($salonId, $userId, $this->telGor($uid, $salonId)));
    }

    /** POST /api/v2/form/arsiv — müşteriye form gönder */
    public function formGonder(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::SAYFA);
        $this->yazmaAcikMi();
        $gonder = $this->yetkiler($uid, $salonId)['gonder'];

        $sonuc = $this->islem->formGonder($salonId, $uid, [
            'form_id'       => $request->integer('form_id'),
            'user_id'       => $request->integer('user_id'),
            'telefon'       => $request->input('telefon'),
            'personel_id'   => $request->integer('personel_id') ?: null,
            'hizmet_id'     => $request->integer('hizmet_id') ?: null,
            'toplam_ucret'  => $this->tutar($request->input('toplam_ucret')),
            'kapora'        => $this->tutar($request->input('kapora')),
            'sadece_kaydet' => ! $gonder || $request->boolean('sadece_kaydet'),
            'kara_liste_atla' => $request->boolean('kara_liste_atla'),
        ]);

        return ApiCevap::basarili($sonuc, $sonuc['gonderildi'] ? 'Form başarıyla gönderildi' : 'Form başarıyla kaydedildi');
    }

    /** POST /api/v2/form/arsiv/{id}/gonder {genel_link?} — formu müşteriye yeniden gönder (form.gonder) */
    public function tekrarGonder(Request $request, int $id)
    {
        $uid = $this->arsivKapsami($id, ['form.gonder']);
        $this->yazmaAcikMi();
        $sonuc = $this->islem->tekrarGonder($id, $uid, $request->boolean('genel_link'));

        return ApiCevap::basarili($sonuc, $sonuc['gonderildi'] ? 'Form başarıyla gönderildi' : 'Müşteri kara listede; mesaj gönderilmedi');
    }

    /** POST /api/v2/form/arsiv/{id}/onayla */
    public function onayla(int $id)
    {
        $uid = $this->arsivKapsami($id, self::SAYFA);
        $this->yazmaAcikMi();
        $this->islem->onayla($id, $uid);

        return ApiCevap::basarili(null, 'İşlem Başarılı');
    }

    /** POST /api/v2/form/arsiv/{id}/iptal */
    public function iptal(int $id)
    {
        $uid = $this->arsivKapsami($id, self::SAYFA);
        $this->yazmaAcikMi();
        $this->islem->iptal($id, $uid);

        return ApiCevap::basarili(null, 'İşlem Başarılı');
    }

    /** POST /api/v2/form/arsiv/harici (multipart: salon_id, baslik, user_id, personel_id, dosya) — form.olustur */
    public function hariciBelge(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, ['form.olustur']);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->islem->hariciBelgeEkle($salonId, $uid, [
            'baslik'      => $request->input('baslik'),
            'user_id'     => $request->integer('user_id'),
            'personel_id' => $request->integer('personel_id'),
        ], $request->file('dosya')), 'Form başarıyla eklendi');
    }

    /**
     * POST /api/v2/form/sozlesme — hizmet sözleşmesi oluştur + imza linki gönder (form.gonder).
     * sadece_kaydet=1: mesaj gitmez, form.olustur da yeter (form gönderimindeki kuralla aynı).
     */
    public function sozlesme(Request $request)
    {
        $sadeceKaydet = $request->boolean('sadece_kaydet');
        [$uid, $salonId] = $this->salonDenetle($request, $sadeceKaydet ? self::SAYFA : ['form.gonder']);
        $this->yazmaAcikMi();
        $g = $request->only([
            'user_id', 'cep_telefon', 'hizmet_id', 'paket_id', 'seans_sayisi', 'sozlesme_notu', 'sozlesme_metni',
            'odeme_sekli', 'taksit_sayisi', 'ilk_taksit_tarihi', 'gecerlilik_tarihi',
            'salon_imza', 'salon_yetkili_ad', 'salon_yetkili_telefon',
        ]);
        $g['toplam_ucret'] = $this->tutar($request->input('toplam_ucret')) ?? 0;
        $g['kapora'] = $this->tutar($request->input('kapora')) ?? 0;
        $g['taksit_tutari'] = $this->tutar($request->input('taksit_tutari'));
        $g['sadece_kaydet'] = $sadeceKaydet;
        $g['mobil_sozlesme'] = $request->boolean('mobil_sozlesme');

        return ApiCevap::basarili(
            $this->islem->sozlesmeOlustur($salonId, $uid, $g, (string) $request->ip()),
            $sadeceKaydet ? 'Sözleşme arşive kaydedildi.' : 'Sözleşme oluşturuldu ve müşteriye gönderildi.'
        );
    }

    /**
     * GET /api/v2/form/sozlesme/odeme-plani?salon_id=&toplam_ucret=&kapora=&taksit_sayisi=&taksit_tutari=&ilk_taksit_tarihi=
     * Sözleşme modalındaki taksit önizlemesi — kayıtta kullanılan AYNI hesap (istemci hesap kopyalamaz).
     */
    public function odemePlani(Request $request)
    {
        $this->salonDenetle($request, ['form.gonder']);

        return ApiCevap::basarili(OdemePlani::uret(
            $this->tutar($request->input('toplam_ucret')) ?? 0,
            $this->tutar($request->input('kapora')) ?? 0,
            min(60, max(1, $request->integer('taksit_sayisi'))),
            $this->tutar($request->input('taksit_tutari')),
            $request->input('ilk_taksit_tarihi') ?: null,
        ));
    }

    // ---------------------------------------------------------------------

    /** Kaydın salonu üzerinden kapsam + yetki; yetkili id döner. */
    private function arsivKapsami(int $arsivId, array $yetkiler): int
    {
        $user = $this->kullanici();
        $this->kapi->denetle($user->id, $this->bulundu($this->islem->salonId($arsivId), 'Kayıt bulunamadı.'), $yetkiler);

        return (int) $user->id;
    }

    /** @return array{olustur:bool,gonder:bool} */
    private function yetkiler(int $uid, int $salonId): array
    {
        return [
            'olustur' => $this->yetki->yetkiliYetkiVar($uid, $salonId, 'form.olustur'),
            'gonder'  => $this->yetki->yetkiliYetkiVar($uid, $salonId, 'form.gonder'),
        ];
    }

    private function telGor(int $uid, int $salonId): bool
    {
        return $this->yetki->yetkiliYetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }

    /** "1.234,56" / "1234.56" / "" → float|null */
    private function tutar($v): ?float
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        $v = trim((string) $v);
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }
}
