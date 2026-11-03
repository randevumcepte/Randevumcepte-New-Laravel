<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Cark\CarkSekmeleri;
use App\Domain\Cark\DilimTipi;
use App\Domain\Cark\KuponDurumu;
use App\Http\Requests\Cark\CarkKaydetIstegi;
use App\Http\Requests\Cark\HatirlatmaKaydetIstegi;
use App\Services\Cark\CarkAyarServisi;
use App\Services\Cark\CarkHatirlatmaServisi;
use App\Services\Yetki\SubeGrubu;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * ÇARKIFELEK AYARI v2 (eski /isletmeyonetim/carkifelek + carkhatirlatma, mobil carkAdmin/sistem,
 * dilim-kaydet, aktif-toggle, bildirim-gonder, hatirlatma) — ince controller.
 *
 *   GET   /cark?salon_id=             açılış: sekmeler, sistem + dilimler, sözlükler, şube seçenekleri
 *   PUT   /cark                       dilimler + kurallar + süreler + aktiflik (+ salon_ids çoklu şube)
 *   PATCH /cark/aktif   {aktifmi}     yalnız aktif/pasif (şube grubuna yansır)
 *   POST  /cark/duyuru  {baslik?, mesaj?}  uygulamalı müşterilere push (outbox 'cark_duyuru')
 *   GET   /cark/hatirlatma · PUT /cark/hatirlatma
 *
 * Yetki: pazarlama.cark_yonet (eski sayfa + menü anahtarı). Yazmalar CARK_V2_YAZMA_AKTIF arkasında.
 */
class CarkController extends V2Controller
{
    public const YONET = 'pazarlama.cark_yonet';

    protected ?string $yazmaAlani = 'cark';

    protected string $yazmaKapaliMesaj = 'Çarkıfelek v2 yazma şu an kapalı';

    public function __construct(private CarkAyarServisi $ayar) {}

    public function acilis(Request $request, SubeGrubu $subeler)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YONET);

        return ApiCevap::basarili($this->ayar->getir($salonId) + [
            'sekmeler' => CarkSekmeleri::liste(),
            'sozluk'   => [
                'dilim_tipleri'       => DilimTipi::sozluk(),
                'kupon_durumlari'     => KuponDurumu::sozluk(),
                'renkler'             => config('cark.renkler'),
                'dilim_min'           => config('cark.dilim_min'),
                'dilim_max'           => config('cark.dilim_max'),
                'varsayilan_dilimler' => config('cark.varsayilan_dilimler'),
            ],
            'subeler'  => $subeler->secenekler($uid, $salonId),
            'yazabilir' => (bool) config('cark.yazma_aktif'),
        ]);
    }

    public function kaydet(CarkKaydetIstegi $request, SubeGrubu $subeler)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YONET);
        $this->yazmaAcikMi();
        $hedefler = $subeler->hedefler($uid, $salonId, $request->input('salon_ids'), self::YONET);
        $this->ayar->kaydet($salonId, $uid, $request->validated(), $hedefler);

        return ApiCevap::basarili($this->ayar->getir($salonId) + ['subeler_uygulanan' => $hedefler], 'Çark ayarı kaydedildi');
    }

    public function aktiflik(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YONET);
        $v = $request->validate(['aktifmi' => ['required', 'boolean']]);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['aktifmi' => $this->ayar->aktiflik($salonId, $uid, (bool) $v['aktifmi'])],
            $v['aktifmi'] ? 'Çark aktif edildi' : 'Çark pasife alındı');
    }

    public function duyuru(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YONET);
        $v = $request->validate(['baslik' => ['nullable', 'string', 'max:80'], 'mesaj' => ['nullable', 'string', 'max:300']]);
        $this->yazmaAcikMi();
        $this->ayar->duyuruKuyrugaAl($salonId, $uid, $v['baslik'] ?? null, $v['mesaj'] ?? null);

        return ApiCevap::basarili(['kuyrukta' => true], 'Duyuru kuyruğa alındı; uygulaması yüklü müşterilere birkaç saniye içinde gönderilir');
    }

    public function hatirlatma(Request $request, CarkHatirlatmaServisi $hatirlatma)
    {
        [, $salonId] = $this->salonDenetle($request, self::YONET);

        return ApiCevap::basarili($hatirlatma->getir($salonId));
    }

    public function hatirlatmaKaydet(HatirlatmaKaydetIstegi $request, CarkHatirlatmaServisi $hatirlatma)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YONET);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($hatirlatma->kaydet($salonId, $uid, $request->validated()), 'Hatırlatma ayarları kaydedildi');
    }
}
