<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\CagriMerkeziEylemi;
use App\Exceptions\GecersizIslemException;
use App\Services\CagriMerkezi\CagriMerkeziErisimServisi;
use Illuminate\Http\Request;

/**
 * Çağrı Merkezi controller'larının ortak tabanı — salon_id + kimlik + erişim çözümü ve yazma
 * kill-switch'i TEK yerde (CAGRI_MERKEZI_V2_YAZMA_AKTIF, kapalıyken 409). İş mantığı servislerde.
 */
abstract class CagriMerkeziTabanController extends V2Controller
{
    protected ?string $yazmaAlani = 'cagri_merkezi';

    protected string $yazmaKapaliMesaj = 'Çağrı merkezi v2 yazma şu an kapalı';

    /** İstekteki salon için erişim (kapsam + paket + rol + dahili + yetki bayrakları) + varsa eylem kapısı. */
    protected function erisim(Request $request, ?CagriMerkeziEylemi $eylem = null): CagriMerkeziErisim
    {
        $salonId = (int) $request->input('salon_id');
        if (! $salonId) {
            throw new GecersizIslemException('salon_id zorunlu');
        }

        $servis = app(CagriMerkeziErisimServisi::class);
        $e = $servis->coz((int) $this->kullanici()->id, $salonId);
        if ($eylem) {
            $servis->eylemZorunlu($e, $eylem);
        }

        return $e;
    }

    /** Yazma uçları: önce erişim (403/404 sızıntısız), sonra kill-switch. */
    protected function yazmaErisimi(Request $request, ?CagriMerkeziEylemi $eylem = null): CagriMerkeziErisim
    {
        $e = $this->erisim($request, $eylem);
        $this->yazmaAcikMi();

        return $e;
    }

    /** Tarih (Y-m-d) + saat (H:i) doğrulaması — arama randevusu / erteleme / sonuç. */
    protected static function zamanKurallari(bool $zorunlu): array
    {
        $k = $zorunlu ? 'required' : 'nullable';

        return ['tarih' => [$k, 'date_format:Y-m-d'], 'saat' => [$k, 'date_format:H:i']];
    }
}
