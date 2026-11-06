<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Denetim;
use App\Services\SistemYonetim\Entegrasyon\WhatsappLogSorgusu;
use App\Services\Whatsapp\WhatsappGonderimSorgusu;
use App\Support\ApiCevap;
use App\Support\CsvCevabi;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 * WhatsApp mesaj loglari — KVKK: telefon ve mesaj icerigi; izleyici goremez (rota), destek yalniz kendi salonlari.
 *   GET /sistem/whatsapp-yonetim/loglar?salon_id&durum&telefon&baslangic&bitis + ListeIstegi
 *   GET /sistem/whatsapp-yonetim/loglar/csv (ayni filtreler, en fazla 50000 satir)
 *   GET /sistem/whatsapp-yonetim/mesaj/{id}
 *   GET /sistem/whatsapp-yonetim/salon/{id}/aliciler + ListeIstegi
 *   GET /sistem/whatsapp-yonetim/salon/{id}/aliciler/{logId}/gecmis
 */
class WhatsappYonetimLogController extends SistemTabanController
{
    protected const ETIKETLER = ['salon_id' => 'Salon ID', 'durum' => 'Durum', 'baslangic' => 'Başlangıç', 'bitis' => 'Bitiş'];

    public function __construct(private WhatsappLogSorgusu $loglar, private WhatsappGonderimSorgusu $gonderim) {}

    public function liste(Request $request)
    {
        $liste = ListeIstegi::istekten($request, 50, array_keys(WhatsappLogSorgusu::SIRALAMA), 'id', 'desc')->dizi();

        return ApiCevap::basarili($this->loglar->liste($this->filtre($request), $liste, $this->yonetici()));
    }

    public function csv(Request $request)
    {
        $filtre = $this->filtre($request) + ['ara' => $request->input('ara')];
        Denetim::yaz('whatsapp_log_csv', 'whatsapp_log', null, 'WhatsApp logları', 'CSV indirildi', array_filter($filtre));

        return CsvCevabi::indir('whatsapp_loglari_'.date('Ymd_His').'.csv', WhatsappLogSorgusu::CSV_BASLIKLAR, $this->loglar->csvSatirlari($filtre, $this->yonetici()));
    }

    public function mesaj(int $id)
    {
        return ApiCevap::basarili($this->loglar->mesaj($id, $this->yonetici()));
    }

    public function aliciler(Request $request, int $id)
    {
        $salon = $this->salon($id, ['id', 'salon_adi', 'whatsapp_numara']);
        $liste = ListeIstegi::istekten($request, 25, array_keys(WhatsappGonderimSorgusu::ALICI_SIRALAMA), 'son', 'desc')->dizi();

        return ApiCevap::basarili($this->gonderim->aliciler($id, $liste, true) + ['salon' => ['id' => (int) $salon->id, 'salon_adi' => $salon->salon_adi, 'whatsapp_numara' => $salon->whatsapp_numara]]);
    }

    public function aliciGecmisi(int $id, int $logId)
    {
        $this->salon($id, ['id']);

        return ApiCevap::basarili($this->gonderim->aliciGecmisi($id, $logId, true));
    }

    private function filtre(Request $request): array
    {
        return $this->dogrula($request, [
            'salon_id'  => ['nullable', 'integer', 'min:1'],
            'durum'     => ['nullable', 'integer', 'min:0', 'max:9'],
            'telefon'   => ['nullable', 'string', 'max:20'],
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
            'bitis'     => ['nullable', 'date_format:Y-m-d'],
        ]);
    }
}
