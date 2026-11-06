<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Entegrasyon\SistemWhatsappServisi;
use App\Support\ApiCevap;
use Illuminate\Http\Request;

/**
 * Salon-bagimsiz sistem WhatsApp oturumu. Tamami super_admin (rota).
 *   GET  /sistem/sistem-whatsapp          → ayar
 *   PUT  /sistem/sistem-whatsapp/ayar     {numara?, gonderen_numara?, aktif}
 *   GET  /sistem/sistem-whatsapp/durum    → status, etiket, renk, bagli, servis_acik, phone, uyari
 *   GET  /sistem/sistem-whatsapp/qr       → qr (PNG data URI | null), teshis
 *   POST /sistem/sistem-whatsapp/baslat | cikis | test
 *   POST /sistem/sistem-whatsapp/eslestir {telefon} → code, gosterim
 */
class SistemWhatsappController extends SistemTabanController
{
    protected const ETIKETLER = ['numara' => 'Alıcı numara', 'gonderen_numara' => 'Gönderen numara', 'aktif' => 'Bildirim durumu'];

    public function __construct(private SistemWhatsappServisi $servis) {}

    public function ayar()
    {
        return ApiCevap::basarili($this->servis->ayar());
    }

    public function ayarKaydet(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'numara'          => ['nullable', 'string', 'max:500'],
            'gonderen_numara' => ['nullable', 'string', 'max:30'],
            'aktif'           => ['required', 'boolean'],
        ]);

        return ApiCevap::basarili($this->servis->ayarKaydet($g['numara'] ?? null, (bool) $g['aktif'], $g['gonderen_numara'] ?? null), 'Bildirim ayarı kaydedildi.');
    }

    public function durum()
    {
        return ApiCevap::basarili($this->servis->durum());
    }

    public function qr()
    {
        return ApiCevap::basarili($this->servis->qr());
    }

    public function baslat()
    {
        $this->yazmaAcikMi();
        $this->servis->baslat();

        return ApiCevap::basarili($this->servis->durum());
    }

    public function eslestir(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, ['telefon' => ['required', 'string', 'regex:/^\D*(\d\D*){10,15}$/']]);

        return ApiCevap::basarili($this->servis->eslestir($g['telefon']));
    }

    public function cikis()
    {
        $this->yazmaAcikMi();
        $this->servis->cikis();

        return ApiCevap::basarili(null, 'Oturum sıfırlandı — şimdi "Bağlan / QR Göster" ile yeni numarayı bağla.');
    }

    public function test()
    {
        $this->yazmaAcikMi();

        $ozet = $this->servis->testGonder();

        return ApiCevap::basarili(['ozet' => $ozet], $ozet);
    }
}
