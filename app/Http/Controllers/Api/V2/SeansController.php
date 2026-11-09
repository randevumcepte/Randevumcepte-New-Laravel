<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Seans\MobilSeansKurali;
use App\Domain\Seans\SeansDurumu;
use App\Domain\Seans\SeansKalemTuru;
use App\Services\Randevu\MusteriDogrulamaKodu;
use App\Services\Seans\SeansCihazServisi;
use App\Services\Seans\SeansDokumServisi;
use App\Services\Seans\SeansKapsam;
use App\Services\Seans\SeansMobilServisi;
use App\Services\Seans\SeansTakipServisi;
use App\Services\Seans\SeansYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\DogrulamaMetinleri;
use App\Support\Idempotency;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Seans Takibi API v2 (ince controller) — web paneli (/v2/seans-takibi, musteri
 * detay "Seanslar" sekmesi) ve Flutter AYNI uclari kullanir. v1 esleme tablosu:
 * docs/flutter-uyum-yol-haritasi.md "Seans takibi".
 *
 *   GET   /seans?salon_id=&musteri_id= + ListeIstegi   satirlar + kartlar + seanslar + sayaclar
 *   POST  /seans {tur, kalem_id, hizmet_id, geldi, tarih, dogrulama_kodu?}  bos daireye kullanim ekle
 *   PATCH /seans/{id} {geldi: 0|1|2|null, dogrulama_kodu?}                 durum (null = beklemede)
 *
 * SMS ayari 16 aciksa 'geldi' (1) isareti musteri dogrulama kodu ister (randevu geldi ile ortak ayar ve kanal):
 * kod yoksa musteriye gider ve 428 {dogrulama_gerekli:true} doner; istemci kodla ayni istegi tekrarlar.
 *   GET   /seans/{id}/cihaz                               lazer cihaz bilgisi + personeller
 *   PUT   /seans/{id}/cihaz {bolgeler: [...]}              lazer cihaz bilgisi kaydet
 *   GET   /seans/dokum?tur=&kalem_id=                      seans dokumu PDF (mobil)
 *
 * mobil=1 (yalniz imzali mobil token, MobilBayraklar): eski mobil sayac, satir ve yazma kurali
 * (SeansMobilServisi), yetki anahtari aranmaz, kimlik ve salon kapsami zorunlu.
 *
 * Yetki her uçta 'paket.seans_takip' (eski sayfa kapisi). Kayit uclarinda salon
 * istekten degil kaydin adisyonundan cozulur (baska salonun kaydi 404/403).
 * Yazmalar SEANS_V2_YAZMA_AKTIF arkasinda (kapaliyken 409) — kapsam/yetki SONRASI.
 */
class SeansController extends V2Controller
{
    private const YETKI = 'paket.seans_takip';

    private const ALANLAR = [
        'tur' => 'Tür', 'kalem_id' => 'Paket / hizmet satışı', 'hizmet_id' => 'Hizmet', 'geldi' => 'Seans durumu',
        'tarih' => 'Seans tarihi', 'bolgeler' => 'Bölgeler', 'bolgeler.*.personel_id' => 'Uygulayan personel',
    ];

    protected ?string $yazmaAlani = 'seans';

    protected string $yazmaKapaliMesaj = 'Seans takibi v2 yazma şu an kapalı';

    public function __construct(
        private SeansKapsam $kapsam,
        private SeansTakipServisi $takip,
        private SeansYazServisi $yaz,
        private SeansCihazServisi $cihaz,
        private SeansMobilServisi $mobil,
        private MusteriDogrulamaKodu $dogrulama,
    ) {}

    public function liste(Request $request)
    {
        if ($request->boolean('mobil')) {
            [, $salonId] = $this->salonDenetle($request, []);

            return ApiCevap::basarili($this->mobil->liste($salonId, (string) $request->input('arama', ''), (string) $request->input('musteri_id', ''), max(1, $request->integer('page', 1))));
        }

        [, $salonId] = $this->salonDenetle($request, self::YETKI);
        $l = ListeIstegi::istekten($request, 10, array_keys(SeansTakipServisi::SIRALANABILIR), 'tarih', 'desc');

        return ApiCevap::basarili($this->takip->liste($salonId, $l, $request->integer('musteri_id') ?: null));
    }

    public function ekle(Request $request)
    {
        if ($request->boolean('mobil')) {
            return $this->mobilEkle($request);
        }

        $g = $this->dogrula($request, [
            'tur'       => ['required', 'in:'.implode(',', array_column(SeansKalemTuru::cases(), 'value'))],
            'kalem_id'  => ['required', 'integer', 'min:1'],
            'hizmet_id' => ['required', 'integer', 'min:1'],
            'geldi'     => ['required', 'integer', 'in:0,1,2'],
            'tarih'     => ['nullable', 'date_format:Y-m-d'],
        ]);
        $tur = SeansKalemTuru::from($g['tur']);
        $kalem = $this->kapsam->kalem($tur, (int) $g['kalem_id']);
        $this->kayitDenetle((int) $kalem->salon_id);
        $this->geldiKodu($request, $g['geldi'], $kalem, ['kalem' => $tur->value.':'.$kalem->id, 'hizmet' => (int) $g['hizmet_id']]);

        $sonuc = $this->yaz->ekle($tur, $kalem, (int) $g['hizmet_id'], SeansDurumu::geldidenCoz($g['geldi']), $g['tarih'] ?? now()->toDateString());

        return ApiCevap::basarili($sonuc + ['satir' => $this->takip->kalem((int) $kalem->salon_id, $tur, (int) $kalem->id)], 'Seans eklendi', 201);
    }

    public function durum(Request $request, int $id)
    {
        $g = $this->dogrula($request, ['geldi' => ['present', 'nullable', 'integer', 'in:0,1,2']]);
        [$seans, $tur, $kalem] = $this->kapsam->seans($id);
        $geldiKodu = fn () => $this->geldiKodu($request, $g['geldi'] ?? null, $kalem, ['seans' => (int) $seans->id]);
        if ($request->boolean('mobil')) {
            $this->kayitDenetle((int) $kalem->salon_id, true, true);
            $geldiKodu();

            return ApiCevap::basarili($this->mobil->durum($seans, isset($g['geldi']) ? (int) $g['geldi'] : null, (int) $kalem->salon_id, (int) $this->kullanici()->id));
        }
        $this->kayitDenetle((int) $kalem->salon_id);
        $geldiKodu();

        $durum = SeansDurumu::geldidenCoz($g['geldi']);
        $sonuc = $this->yaz->durumGuncelle($seans, $durum);

        return ApiCevap::basarili(
            $sonuc + ['satir' => $this->takip->kalem((int) $kalem->salon_id, $tur, (int) $kalem->id)],
            $sonuc['silindi'] ? 'Seans kullanımı geri alındı' : 'Seans '.mb_strtolower($durum->etiket()).' olarak güncellendi',
        );
    }

    public function cihaz(int $id)
    {
        [$seans, $tur, $kalem] = $this->kapsam->seans($id);
        $this->kayitDenetle((int) $kalem->salon_id, false);

        return ApiCevap::basarili($this->cihaz->getir($seans, $tur, $kalem));
    }

    public function cihazKaydet(Request $request, int $id)
    {
        $g = $this->dogrula($request, [
            'bolgeler'                    => ['present', 'array', 'max:20'],
            'bolgeler.*'                  => ['array'],
            'bolgeler.*.uygulama_bolgesi' => ['nullable', 'string', 'max:150'],
            'bolgeler.*.enerji'           => ['nullable', 'string', 'max:50'],
            'bolgeler.*.hiz'              => ['nullable', 'string', 'max:50'],
            'bolgeler.*.ms'               => ['nullable', 'string', 'max:50'],
            'bolgeler.*.atis_sayisi'      => ['nullable', 'string', 'max:50'],
            'bolgeler.*.personel_id'      => ['nullable', 'integer', 'min:1'],
            'bolgeler.*.notlar'           => ['nullable', 'string', 'max:1000'],
        ]);
        [$seans, $tur, $kalem] = $this->kapsam->seans($id);
        $this->kayitDenetle((int) $kalem->salon_id);

        $adet = $this->cihaz->kaydet($seans, $tur, $kalem, $g['bolgeler'], (int) $this->kullanici()->id);

        return ApiCevap::basarili(['kayit_sayisi' => $adet], 'Cihaz bilgileri kaydedildi');
    }

    public function dokum(Request $request, SeansDokumServisi $dokum)
    {
        $g = $this->dogrula($request, [
            'tur'      => ['required', 'in:'.implode(',', array_column(SeansKalemTuru::cases(), 'value'))],
            'kalem_id' => ['required', 'integer', 'min:1'],
        ]);
        $tur = SeansKalemTuru::from($g['tur']);
        $kalem = $this->kapsam->kalem($tur, (int) $g['kalem_id']);
        $this->kayitDenetle((int) $kalem->salon_id, false, $request->boolean('mobil'));
        $belge = $dokum->pdf($tur, $kalem);

        return $belge['pdf']->stream($belge['ad']);
    }

    private function mobilEkle(Request $request)
    {
        $g = $this->dogrula($request, [
            'tur'             => ['required', 'in:'.implode(',', array_column(SeansKalemTuru::cases(), 'value'))],
            'kalem_id'        => ['required', 'integer', 'min:1'],
            'hizmet_id'       => ['required', 'integer', 'min:1'],
            'geldi'           => ['required', 'integer', 'in:0,1,2'],
            'tarih'           => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $tur = SeansKalemTuru::from($g['tur']);
        $kalem = $this->kapsam->kalem($tur, (int) $g['kalem_id']);
        $salonId = (int) $kalem->salon_id;
        $this->kayitDenetle($salonId, true, true);
        $this->geldiKodu($request, $g['geldi'], $kalem, ['kalem' => $tur->value.':'.$kalem->id, 'hizmet' => (int) $g['hizmet_id']]);
        $uid = (int) $this->kullanici()->id;
        $tarih = MobilSeansKurali::seansTarihi($g['tarih'] ?? null, now()->toDateString());

        return ApiCevap::basarili(
            Idempotency::calistir('seans_mobil_ekle', $g['idempotency_key'], $uid, $salonId, fn () => $this->mobil->ekle($tur, $kalem, (int) $g['hizmet_id'], (int) $g['geldi'], $tarih, $uid)),
            'Seans eklendi',
            201,
        );
    }

    /** Yalniz 'geldi' (1) isareti: SMS ayari 16 aciksa musteri dogrulama kodu (web ve mobil ayni kapi). */
    private function geldiKodu(Request $request, mixed $geldi, object $kalem, array $baglam): void
    {
        if (SeansDurumu::geldidenCoz($geldi) === SeansDurumu::GELDI) {
            $this->dogrulama->seansGeldi((int) $kalem->salon_id, $kalem->user_id ? (int) $kalem->user_id : null, $baglam, $request->input('dogrulama_kodu'));
        }
    }

    /** Kaydin salonunda kapsam + yetki (mobilde yalniz kapsam); yazma ise ardindan kill-switch. */
    private function kayitDenetle(int $salonId, bool $yazma = true, bool $mobil = false): void
    {
        $mobil
            ? app(GuvenlikKapisi::class)->kapsamDenetle($this->kullanici()->id, $salonId)
            : app(GuvenlikKapisi::class)->denetle($this->kullanici()->id, $salonId, self::YETKI);
        if ($yazma) {
            $this->yazmaAcikMi();
        }
    }

    private function dogrula(Request $request, array $kurallar): array
    {
        return $request->validate($kurallar, DogrulamaMetinleri::mesajlar(), self::ALANLAR + DogrulamaMetinleri::alanlar());
    }
}
