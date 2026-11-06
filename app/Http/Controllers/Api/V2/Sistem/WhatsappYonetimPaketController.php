<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Entegrasyon\WhatsappPaketKatalogu as Katalog;
use App\Services\SistemYonetim\Entegrasyon\WhatsappPaketServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Salon WhatsApp paketi ve kontoru. Okuma tum roller; yazma super_admin,yonetici (rota).
 *   GET  /sistem/whatsapp-yonetim/salon/{id}/paket
 *   PUT  /sistem/whatsapp-yonetim/salon/{id}/paket {paket, periyot?, baslangic?, bitis?}
 *   POST /sistem/whatsapp-yonetim/salon/{id}/deneme {paket?, gun?}
 *   POST /sistem/whatsapp-yonetim/salon/{id}/deneme/iptal
 *   POST /sistem/whatsapp-yonetim/salon/{id}/kontor {adet, aciklama?} (negatif adet duser)
 */
class WhatsappYonetimPaketController extends SistemTabanController
{
    protected const ETIKETLER = ['paket' => 'Paket', 'periyot' => 'Periyot', 'baslangic' => 'Başlangıç', 'bitis' => 'Bitiş', 'gun' => 'Gün', 'adet' => 'Kontör miktarı'];

    public function __construct(private WhatsappPaketServisi $servis) {}

    public function detay(int $id)
    {
        return ApiCevap::basarili($this->servis->detay($this->salon($id, WhatsappPaketServisi::KOLONLAR)));
    }

    public function ayarla(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'paket'     => ['required', Rule::in(array_keys(Katalog::PAKETLER))],
            'periyot'   => ['nullable', Rule::in(array_keys(Katalog::PERIYOTLAR))],
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
            'bitis'     => ['nullable', 'date_format:Y-m-d'],
        ]);
        $this->servis->paketAyarla($this->salon($id, WhatsappPaketServisi::KOLONLAR), $g);

        return $this->detayla($id, 'Paket kaydedildi.');
    }

    public function denemeBaslat(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'paket' => ['nullable', Rule::in(Katalog::DENEME_PAKETLERI)],
            'gun'   => ['nullable', 'integer', 'min:'.Katalog::DENEME_MIN_GUN, 'max:'.Katalog::DENEME_MAKS_GUN],
        ]);
        $r = $this->servis->denemeBaslat(
            $this->salon($id, WhatsappPaketServisi::KOLONLAR),
            $g['paket'] ?? Katalog::DENEME_VARSAYILAN_PAKET,
            (int) ($g['gun'] ?? Katalog::DENEME_VARSAYILAN_GUN),
        );

        return ApiCevap::basarili($r, "Deneme başlatıldı: {$r['baslangic']} → {$r['bitis']} ({$r['gun']} gün)");
    }

    public function denemeIptal(int $id)
    {
        $this->yazmaAcikMi();
        $this->servis->denemeIptal($this->salon($id, WhatsappPaketServisi::KOLONLAR));

        return ApiCevap::basarili(null, 'İptal edildi.');
    }

    public function kontorYukle(Request $request, int $id)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'adet'     => ['required', 'integer', 'not_in:0', 'min:-10000000', 'max:10000000'],
            'aciklama' => ['nullable', 'string', 'max:160'],
        ]);
        $r = $this->servis->kontorYukle($this->salon($id, ['id', 'salon_adi']), (int) $g['adet'], $g['aciklama'] ?? null);

        return ApiCevap::basarili(['bakiye' => $r['bakiye']], $r['mesaj']);
    }

    private function detayla(int $id, string $mesaj)
    {
        return ApiCevap::basarili($this->servis->detay($this->salon($id, WhatsappPaketServisi::KOLONLAR)), $mesaj);
    }
}
