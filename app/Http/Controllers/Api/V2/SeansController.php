<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Seans\SeansDurumu;
use App\Domain\Seans\SeansKalemTuru;
use App\Services\Seans\SeansCihazServisi;
use App\Services\Seans\SeansKapsam;
use App\Services\Seans\SeansTakipServisi;
use App\Services\Seans\SeansYazServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\DogrulamaMetinleri;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Seans Takibi API v2 (ince controller) — web paneli (/v2/seans-takibi, musteri
 * detay "Seanslar" sekmesi) ve Flutter AYNI uclari kullanir. v1 esleme tablosu:
 * docs/flutter-uyum-yol-haritasi.md "Seans takibi".
 *
 *   GET   /seans?salon_id=&musteri_id= + ListeIstegi   satirlar + kartlar + seanslar + sayaclar
 *   POST  /seans {tur, kalem_id, hizmet_id, geldi, tarih}  bos daireye kullanim ekle
 *   PATCH /seans/{id} {geldi: 0|1|2|null}                 durum (null = beklemede)
 *   GET   /seans/{id}/cihaz                               lazer cihaz bilgisi + personeller
 *   PUT   /seans/{id}/cihaz {bolgeler: [...]}              lazer cihaz bilgisi kaydet
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
    ) {}

    public function liste(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, self::YETKI);
        $l = ListeIstegi::istekten($request, 10, array_keys(SeansTakipServisi::SIRALANABILIR), 'tarih', 'desc');

        return ApiCevap::basarili($this->takip->liste($salonId, $l, $request->integer('musteri_id') ?: null));
    }

    public function ekle(Request $request)
    {
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

        $sonuc = $this->yaz->ekle($tur, $kalem, (int) $g['hizmet_id'], SeansDurumu::geldidenCoz($g['geldi']), $g['tarih'] ?? now()->toDateString());

        return ApiCevap::basarili($sonuc + ['satir' => $this->takip->kalem((int) $kalem->salon_id, $tur, (int) $kalem->id)], 'Seans eklendi', 201);
    }

    public function durum(Request $request, int $id)
    {
        $g = $this->dogrula($request, ['geldi' => ['present', 'nullable', 'integer', 'in:0,1,2']]);
        [$seans, $tur, $kalem] = $this->kapsam->seans($id);
        $this->kayitDenetle((int) $kalem->salon_id);

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

    /** Kaydin salonunda kapsam + yetki; yazma ise ardindan kill-switch. */
    private function kayitDenetle(int $salonId, bool $yazma = true): void
    {
        app(GuvenlikKapisi::class)->denetle($this->kullanici()->id, $salonId, self::YETKI);
        if ($yazma) {
            $this->yazmaAcikMi();
        }
    }

    private function dogrula(Request $request, array $kurallar): array
    {
        return $request->validate($kurallar, DogrulamaMetinleri::mesajlar(), self::ALANLAR + DogrulamaMetinleri::alanlar());
    }
}
