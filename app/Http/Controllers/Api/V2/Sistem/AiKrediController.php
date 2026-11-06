<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Entegrasyon\AiKrediServisi;
use App\Domain\SistemYonetim\SistemYetki;
use App\Support\ApiCevap;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Okuma tum roller; yazma super_admin,yonetici (rota).
 *   GET  /sistem/ai-kredi?gun=1|7|30|0
 *   GET  /sistem/ai-kredi/salonlar?gun + ListeIstegi
 *   PUT  /sistem/ai-kredi/ayar {yuklenen, kur?, esik?}
 *   POST /sistem/ai-kredi/ekle {eklenen}
 *   POST /sistem/ai-kredi/test-alarm
 */
class AiKrediController extends SistemTabanController
{
    private const VARSAYILAN_GUN = 30;

    protected const ETIKETLER = ['yuklenen' => 'Yüklenen kredi', 'kur' => 'Kur', 'esik' => 'Alarm eşiği', 'eklenen' => 'Eklenen kredi', 'gun' => 'Dönem'];

    public function __construct(private AiKrediServisi $servis) {}

    public function rapor(Request $request)
    {
        return ApiCevap::basarili($this->servis->rapor($this->gun($request)) + ['izinler' => ['yaz' => $this->izinli(SistemYetki::YONETIM)]]);
    }

    public function salonlar(Request $request)
    {
        $gun = $this->gun($request);
        $liste = ListeIstegi::istekten($request, 25, array_keys(AiKrediServisi::SALON_SIRALAMA), 'maliyet', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->salonlar($gun, $liste));
    }

    public function ayarKaydet(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, [
            'yuklenen' => ['required', 'numeric', 'min:0'],
            'kur'      => ['nullable', 'numeric', 'min:1'],
            'esik'     => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->servis->ayarKaydet((float) $g['yuklenen'], isset($g['kur']) ? (float) $g['kur'] : null, isset($g['esik']) ? (float) $g['esik'] : null);

        return ApiCevap::basarili(null, 'Kredi ayarı güncellendi.');
    }

    public function krediEkle(Request $request)
    {
        $this->yazmaAcikMi();
        $g = $this->dogrula($request, ['eklenen' => ['required', 'numeric', 'min:0.01']]);
        $toplam = $this->servis->krediEkle((float) $g['eklenen']);

        return ApiCevap::basarili(['yuklenen_usd' => $toplam], '$'.number_format((float) $g['eklenen'], 2, ',', '.').' eklendi. Yeni toplam: $'.number_format($toplam, 2, ',', '.'));
    }

    public function testAlarm()
    {
        $this->yazmaAcikMi();
        $this->servis->testAlarm();

        return ApiCevap::basarili(null, 'Test alarmı gönderildi (WhatsApp + SMS). Gelmezse Sistem WhatsApp numarası/aktifliğini kontrol edin.');
    }

    private function gun(Request $request): int
    {
        $g = $this->dogrula($request, ['gun' => ['nullable', Rule::in(array_keys(AiKrediServisi::GUN_SECENEKLERI))]]);

        return (int) ($g['gun'] ?? self::VARSAYILAN_GUN);
    }
}
