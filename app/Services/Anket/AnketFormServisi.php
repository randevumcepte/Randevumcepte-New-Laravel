<?php

namespace App\Services\Anket;

use App\Domain\Anket\AnketOdulu;
use App\Domain\Anket\AnketSorulari;
use App\Domain\Anket\GonderimFiltresi;
use App\Domain\Anket\SoruTipi;
use App\Domain\Rapor\RaporDonemi;
use Illuminate\Support\Facades\DB;

/**
 * Ekran açılış verisi — sekmeler, sözlükler, hazır şablon, dönem seçenekleri, yetkiler TEK uçtan.
 * Web ve Flutter sabit liste tutmaz (eskide blade + Flutter'da ayrı kopyalardı).
 */
class AnketFormServisi
{
    private const DONEMLER = ['son7', 'son30', 'son90', 'son365', 'ay', 'gecen_ay', 'yil', 'ozel'];
    public const VARSAYILAN_DONEM = 'son30';

    /** @param array{yaz:bool, telefon_gor:bool} $yetkiler */
    public function formVerisi(int $salonId, array $yetkiler): array
    {
        return [
            'sekmeler' => [
                ['kod' => 'sonuclar', 'ad' => 'Sonuçlar & İstatistik', 'ikon' => 'bi-bar-chart'],
                ['kod' => 'sablonlar', 'ad' => 'Şablonlar', 'ikon' => 'bi-ui-checks-grid'],
            ],
            'soru_katalogu'    => SoruTipi::katalog(),
            'odul_tipleri'     => AnketOdulu::tipSecenekleri(),
            'indirim_tipleri'  => AnketOdulu::indirimSecenekleri(),
            'filtreler'        => GonderimFiltresi::secenekler(),
            'donemler'         => RaporDonemi::secenekListesi(self::DONEMLER),
            'varsayilan_donem' => self::VARSAYILAN_DONEM,
            'hazir_sablon'     => [
                'ad'       => 'Hizmet Sonrası Memnuniyet Anketi',
                'aciklama' => 'Görüşleriniz bizim için çok değerli. Lütfen 1 dakikanızı ayırarak deneyiminizi değerlendirin.',
                'sorular'  => AnketSorulari::hazirSablon(),
            ],
            'varsayilan' => [
                'otomatik_gonder'    => true,
                'gonder_saat_sonra'  => 0,
                'kupon_gecerlilik_gun' => AnketOdulu::VARSAYILAN_GECERLILIK_GUN,
            ],
            'sablon_sayisi' => DB::table('anket_sablonlari')->where('salon_id', $salonId)->count(),
            'yetkiler'      => $yetkiler,
        ];
    }
}
