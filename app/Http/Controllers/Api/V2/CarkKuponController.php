<?php

namespace App\Http\Controllers\Api\V2;

use App\Services\Cark\CarkGecmisServisi;
use App\Services\Cark\CarkKuponServisi;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * ÇARK KAZANANLAR / KUPONLAR v2 (eski /isletmeyonetim/carkkazananlar + carkkupondogrula +
 * carkkuponkullan; mobil carkAdmin/kazananlar, kupon-dogrula, kupon-kullan, kuponlar).
 *
 *   GET  /cark/ozet                           toplam / bugün çevirme, bekleyen / kullanılmış kupon
 *   GET  /cark/kuponlar?durum=tumu|gecerli|kullanildi|sure_doldu + ListeIstegi  → {data, sayaclar, sayfalama}
 *   GET  /cark/cevirmeler + ListeIstegi       çevirme geçmişi
 *   POST /cark/kupon/dogrula {kod}            kodu bu şubede doğrula (kullanmaz)
 *   POST /cark/kupon/{id}/kullan · /geri-al   (satır kilidi; zaten kullanılmış / süresi dolmuş → 409)
 *   GET  /cark/musteri-kuponlari?user_id=     tahsilat ekranı: müşterinin bu şubede geçerli indirim kuponları
 *
 * Yetki: listeler pazarlama.cark_yonet; kupon doğrulama/işaretleme ve müşteri kuponları kasada da
 * yapılır → pazarlama.cark_yonet VEYA satis.tahsilat_al. Açık telefon musteri.telefon_gor.
 */
class CarkKuponController extends V2Controller
{
    private const KASA = [CarkController::YONET, 'satis.tahsilat_al'];

    protected ?string $yazmaAlani = 'cark';

    protected string $yazmaKapaliMesaj = 'Çarkıfelek v2 yazma şu an kapalı';

    public function __construct(private CarkKuponServisi $kuponlar) {}

    public function ozet(Request $request, CarkGecmisServisi $gecmis)
    {
        [, $salonId] = $this->salonDenetle($request, CarkController::YONET);

        return ApiCevap::basarili($gecmis->ozet($salonId));
    }

    public function liste(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, CarkController::YONET);
        $l = ListeIstegi::istekten($request, 25, array_keys(CarkKuponServisi::SIRALAMA), 'islem')->dizi();

        return ApiCevap::basarili($this->kuponlar->liste($salonId, (string) $request->input('durum', $request->input('filtre', '')), $l, $this->telefonGor($uid, $salonId)));
    }

    public function cevirmeler(Request $request, CarkGecmisServisi $gecmis)
    {
        [, $salonId] = $this->salonDenetle($request, CarkController::YONET);
        $l = ListeIstegi::istekten($request, 25, array_keys(CarkGecmisServisi::SIRALAMA), 'tarih')->dizi();

        return ApiCevap::basarili($gecmis->cevirmeler($salonId, $l));
    }

    public function dogrula(Request $request)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::KASA);
        $v = $request->validate(['kod' => ['required', 'string', 'max:20']]);

        return ApiCevap::basarili($this->kuponlar->dogrula($salonId, $uid, $v['kod'], $this->telefonGor($uid, $salonId)));
    }

    public function kullan(Request $request, int $id)
    {
        return $this->isaretle($request, $id, true);
    }

    public function geriAl(Request $request, int $id)
    {
        return $this->isaretle($request, $id, false);
    }

    public function musteriKuponlari(Request $request)
    {
        [, $salonId] = $this->salonDenetle($request, self::KASA);
        $v = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);

        return ApiCevap::basarili(['kuponlar' => $this->kuponlar->musteriKuponlari($salonId, (int) $v['user_id'])]);
    }

    // ───────────────────────────── yardımcılar ─────────────────────────────

    private function isaretle(Request $request, int $id, bool $kullan)
    {
        [$uid, $salonId] = $this->salonDenetle($request, self::KASA);
        $this->yazmaAcikMi();

        return ApiCevap::basarili($this->kuponlar->isaretle($salonId, $uid, $id, $kullan, $this->telefonGor($uid, $salonId)),
            $kullan ? 'Kupon kullanıldı olarak işaretlendi' : 'Kupon kullanımı geri alındı');
    }

    private function telefonGor(int $uid, int $salonId): bool
    {
        return $this->yetkiVar($uid, $salonId, 'musteri.telefon_gor');
    }
}
