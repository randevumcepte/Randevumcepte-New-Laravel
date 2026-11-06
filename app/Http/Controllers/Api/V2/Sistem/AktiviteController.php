<?php

namespace App\Http\Controllers\Api\V2\Sistem;

use App\Services\SistemYonetim\Izleme\AktiviteServisi;
use App\Services\SistemYonetim\Izleme\IzlemeZamani;
use App\Support\ApiCevap;
use App\Support\CsvCevabi;
use App\Support\ListeIstegi;
use Illuminate\Http\Request;

/**
 *   GET /sistem/aktivite/secenekler → {aksiyonlar[], kullanicilar[]}
 *   GET /sistem/aktivite?action=&user_id=&tarih=Y-m-d + ListeIstegi
 *   GET /sistem/aktivite/csv        → son 5000 kayit
 */
class AktiviteController extends SistemTabanController
{
    public function __construct(private AktiviteServisi $servis) {}

    public function secenekler()
    {
        return ApiCevap::basarili($this->servis->secenekler());
    }

    public function liste(Request $request)
    {
        $filtre = $this->alanlariDogrula($request, [
            'action'  => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'tarih'   => ['nullable', 'date_format:Y-m-d'],
        ]);
        $liste = ListeIstegi::istekten($request, 50, array_keys(AktiviteServisi::SIRALAMA), 'zaman', 'desc')->dizi();

        return ApiCevap::basarili($this->servis->listele($liste, $filtre));
    }

    public function csv()
    {
        return CsvCevabi::indir('aktivite-log-'.IzlemeZamani::simdi()->format('Ymd-His').'.csv', AktiviteServisi::CSV_BASLIKLARI, $this->servis->csvSatirlari());
    }
}
