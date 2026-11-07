<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Kampanya\EtkinlikServisi;
use App\Services\Yetki\GuvenlikKapisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * Etkinlik yönetimi (eski v1 etkinlik* uçları). Yetki `pazarlama.kampanya_yonet`, telefon
 * `musteri.telefon_gor`. Yazmalar KAMPANYA_V2_YAZMA_AKTIF arkasındadır.
 *
 *   GET    /etkinlik?salon_id=&ara=&page=
 *   POST   /etkinlik                {salon_id, etkinlik_adi, etkinlik_tarih, etkinlik_saat, etkinlik_mesaj, etkinlik_fiyat, user_idler[]}
 *   PATCH  /etkinlik/{id}           aynı alanlar
 *   DELETE /etkinlik/{id}           pasif yap
 *   POST   /etkinlik/{id}/tekrar-sms
 */
class EtkinlikController extends V2Controller
{
    private const YETKI = 'pazarlama.kampanya_yonet';

    private const KURALLAR = [
        'etkinlik_adi'   => ['nullable', 'string', 'max:255'],
        'etkinlik_tarih' => ['nullable', 'string', 'max:20'],
        'etkinlik_saat'  => ['nullable', 'string', 'max:10'],
        'etkinlik_mesaj' => ['nullable', 'string', 'max:2000'],
        'etkinlik_fiyat' => ['nullable', 'string', 'max:30'],
        'user_idler'     => ['nullable', 'array'],
        'user_idler.*'   => ['integer', 'min:1'],
    ];

    protected ?string $yazmaAlani = 'kampanya';

    protected string $yazmaKapaliMesaj = 'Etkinlik yazma şu an kapalı';

    public function __construct(private EtkinlikServisi $servis) {}

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $liste = ListeIstegi::istekten($request, 10, [], 'id', 'asc')->dizi();

        return ApiCevap::basarili($this->servis->listele($salonId, $liste, $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor')));
    }

    public function ekle(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::YETKI);
        $v = $this->alanlariDogrula($request, self::KURALLAR);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['id' => $this->servis->kaydet($salonId, null, $v, $uid)], 'Etkinlik kaydedildi', 201);
    }

    public function guncelle(Request $request, int $id)
    {
        [$uid, $salonId] = $this->etkinlik($id);
        $v = $this->alanlariDogrula($request, self::KURALLAR);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['id' => $this->servis->kaydet($salonId, $id, $v, $uid)], 'Etkinlik kaydedildi');
    }

    public function sil(int $id)
    {
        [$uid, $salonId] = $this->etkinlik($id);
        $this->yazmaAcikMi();
        $this->servis->pasifYap($salonId, $id, $uid);

        return ApiCevap::basarili(['id' => $id], 'Etkinlik silindi');
    }

    public function tekrarSms(int $id)
    {
        [$uid, $salonId] = $this->etkinlik($id);
        $this->yazmaAcikMi();

        return ApiCevap::basarili(['adet' => $this->servis->tekrarSms($salonId, $id, $uid)], 'SMS başarıyla gönderildi');
    }

    /** @return array{0:int,1:int} */
    private function etkinlik(int $id): array
    {
        $uid = (int) $this->kullanici()->id;
        $salonId = $this->bulundu($this->servis->salonu($id), 'Etkinlik bulunamadı');
        app(GuvenlikKapisi::class)->denetle($uid, $salonId, self::YETKI);

        return [$uid, $salonId];
    }
}
