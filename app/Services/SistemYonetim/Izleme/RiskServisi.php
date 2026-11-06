<?php

namespace App\Services\SistemYonetim\Izleme;

use App\Support\DiziSayfasi;
use App\Domain\SistemYonetim\SaglikSkoru;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Salon\SalonSaglikServisi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Risk altindaki salonlar. Sinyaller SalonSaglikServisi'nden (salon detayiyla ayni skor), puanlama SaglikSkoru'nda.
 * Onbellek tum salonlarin listesini tutar; destek kapsami ve sinir her istekte sonradan uygulanir, boylece destek
 * rolu kendi salonlarini genel sinirin arkasinda kaybetmez ve baska rolun listesini gormez.
 */
class RiskServisi
{
    public const SIRALAMA = ['salon' => 'salon_adi', 'skor' => 'skor', 'durum' => 'durum', 'mt' => 'mt_ad'];

    private const ONBELLEK_ANAHTARI = 'sy.risk.liste';

    private const ONBELLEK_SN = 300;

    private const ESIK = 50;

    private const SINIR = 80;

    private const DURUM_RENK = ['kritik' => 'danger', 'riskli' => 'warning', 'orta' => 'info', 'iyi' => 'success'];

    public function __construct(private SalonSaglikServisi $saglik) {}

    public function listele(SistemYoneticisi $yonetici, array $liste): array
    {
        $satirlar = Cache::remember(self::ONBELLEK_ANAHTARI, self::ONBELLEK_SN, fn () => $this->hesapla());
        if ($yonetici->rol()->salonKapsamliMi()) {
            $satirlar = array_values(array_filter($satirlar, fn (array $s) => (int) $s['mt_id'] === (int) $yonetici->id));
        }

        return DiziSayfasi::sayfala(
            array_slice($satirlar, 0, self::SINIR),
            $liste,
            fn (array $s) => $s['salon_adi'].' '.$s['mt_ad'].' '.implode(' ', $s['sebepler']),
            self::SIRALAMA,
        );
    }

    /** @return list<array> skora gore artan */
    private function hesapla(): array
    {
        $adaylar = DB::table('salonlar')->where('askiya_alindi', 0)->get(['id', 'salon_adi', 'musteri_yetkili_id', 'whatsapp_aktif']);
        if ($adaylar->isEmpty()) {
            return [];
        }
        $sinyalListesi = $this->saglik->sinyaller($adaylar->pluck('id')->map(fn ($id) => (int) $id)->all());

        $satirlar = [];
        foreach ($adaylar as $salon) {
            $id = (int) $salon->id;
            $sinyaller = $sinyalListesi[$id];
            $sonuc = SaglikSkoru::hesapla($sinyaller, ! empty($salon->whatsapp_aktif));
            if ($sonuc['skor'] >= self::ESIK) {
                continue;
            }
            $satirlar[] = [
                'salon_id'   => $id,
                'salon_adi'  => (string) $salon->salon_adi,
                'mt_id'      => $salon->musteri_yetkili_id !== null ? (int) $salon->musteri_yetkili_id : null,
                'mt_ad'      => '',
                'skor'       => $sonuc['skor'],
                'durum'      => $sonuc['durum'],
                'durum_renk' => self::DURUM_RENK[$sonuc['durum']] ?? 'muted',
                'sebepler'   => $sonuc['sebepler'],
                'sinyaller'  => $sinyaller,
            ];
        }
        usort($satirlar, fn (array $a, array $b) => $a['skor'] <=> $b['skor']);

        return $this->temsilciAdlariniEkle($satirlar);
    }

    private function temsilciAdlariniEkle(array $satirlar): array
    {
        $adlar = DB::table('sistemyoneticileri')->whereIn('id', array_filter(array_column($satirlar, 'mt_id')))->pluck('name', 'id');

        return array_map(fn (array $s) => ['mt_ad' => (string) $adlar->get($s['mt_id'], '')] + $s, $satirlar);
    }
}
